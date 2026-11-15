<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Le registre des codes OTP doit être partagé entre le fournisseur qui
        // les émet et le test qui les relit.
        $this->app->singleton(\App\Services\Otp\OtpCodeRegistry::class);
        $this->app->singleton(\App\Services\Messaging\MessageOutbox::class);

        // Fournisseurs SMS et WhatsApp : passerelle HTTP générique par défaut.
        // Brancher un fournisseur dédié revient à changer ces deux liaisons.
        $this->app->singleton(\App\Services\Messaging\SmsProvider::class, \App\Services\Messaging\HttpSmsProvider::class);
        $this->app->singleton(\App\Services\Messaging\WhatsAppProvider::class, \App\Services\Messaging\HttpWhatsAppProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Configure the rate limiters for the application.
     */
    private function configureRateLimiting(): void
    {
        // Default API rate limiter - 60 requests per minute
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // Auth endpoints - more restrictive (prevent brute force)
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // Console d'administration : plafond large par IP, car le verrouillage
        // fin (5 essais par compte, 15 min) est appliqué dans le contrôleur,
        // qui seul peut l'auditer. Un throttle de route trop serré masquerait
        // ce verrouillage et priverait le journal de ses traces.
        RateLimiter::for('admin-auth', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // Activation d'un compte employé (numéro + code) : public, donc borné
        // par IP. Le service ajoute une limite par numéro et par invitation.
        RateLimiter::for('activation', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Authenticated user requests - higher limit
        RateLimiter::for('authenticated', fn (Request $request) => $request->user()
            ? Limit::perMinute(120)->by($request->user()->id)
            : Limit::perMinute(60)->by($request->ip()));
    }
}
