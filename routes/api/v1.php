<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\V1\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Api\V1\Admin\DiagnosticController as AdminDiagnosticController;
use App\Http\Controllers\Api\V1\Admin\OverviewController;
use App\Http\Controllers\Api\V1\Admin\TwoFactorController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\DiagnosticController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\Workshop\ClientController;
use App\Http\Controllers\Api\V1\Workshop\EmployeeController;
use App\Http\Controllers\Api\V1\Workshop\GarmentTypeController;
use App\Http\Controllers\Api\V1\Workshop\MeasurementFieldController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API V1 Routes
|--------------------------------------------------------------------------
|
| Routes for API version 1.
|
*/

// Public routes with auth rate limiter (5/min - brute force protection)
Route::middleware('throttle:auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->name('api.v1.register');
    Route::post('login', [AuthController::class, 'login'])->name('api.v1.login');
    Route::post('auth/refresh', [AuthController::class, 'refresh'])->name('api.v1.auth.refresh');
});

// Protected routes with authenticated rate limiter (120/min)
Route::middleware(['auth:sanctum', 'throttle:authenticated'])->group(function (): void {
    Route::post('logout', [AuthController::class, 'logout'])->name('api.v1.logout');
    Route::get('me', [AuthController::class, 'me'])->name('api.v1.me');

    // Email verification
    Route::post('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')
        ->name('verification.verify');
    Route::post('email/resend', [AuthController::class, 'resendVerificationEmail'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // Diagnostics (throttle : 5 envois/min — bundle ZIP pouvant être volumineux)
    Route::prefix('diagnostics')->middleware('throttle:5,1')->group(function (): void {
        Route::post('bundle', [DiagnosticController::class, 'storeBundle'])->name('api.v1.diagnostics.bundle');
        Route::get('settings', [DiagnosticController::class, 'showSettings'])->name('api.v1.diagnostics.settings.show');
        Route::put('settings', [DiagnosticController::class, 'updateSettings'])->name('api.v1.diagnostics.settings.update');
        Route::post('commands', [DiagnosticController::class, 'storeCommand'])->name('api.v1.diagnostics.commands.store');
        Route::post('commands/{command}/ack', [DiagnosticController::class, 'ackCommand'])->name('api.v1.diagnostics.commands.ack');
    });
});

/*
|--------------------------------------------------------------------------
| Ateliers et synchronisation
|--------------------------------------------------------------------------
|
| `companies` fonde le locataire : c'est le premier appel après connexion,
| celui qui apparie les ateliers locaux à leurs homologues serveur.
|
| Le reste exige `X-Company-Id` (middleware `company`) et, pour la
| synchronisation, `X-Device-Id` (middleware `device`) — l'unité de rattrapage
| est l'installation, pas le compte.
*/
Route::middleware(['auth:sanctum', 'throttle:authenticated'])->group(function (): void {
    Route::prefix('companies')->group(function (): void {
        Route::get('/', [CompanyController::class, 'index'])->name('api.v1.companies.index');
        Route::post('/', [CompanyController::class, 'store'])->name('api.v1.companies.store');
        Route::get('{company}', [CompanyController::class, 'show'])->name('api.v1.companies.show');
        Route::put('{company}', [CompanyController::class, 'update'])->name('api.v1.companies.update');
    });

    Route::prefix('sync')->middleware(['company', 'device'])->group(function (): void {
        Route::get('status', [SyncController::class, 'status'])->name('api.v1.sync.status');
        Route::post('bootstrap', [SyncController::class, 'bootstrap'])->name('api.v1.sync.bootstrap');
        Route::post('pull', [SyncController::class, 'pull'])->name('api.v1.sync.pull');
        Route::post('push', [SyncController::class, 'push'])->name('api.v1.sync.push');
    });

    // CRUD REST du référentiel — console web. Le mobile n'y touche pas : il ne
    // parle qu'aux quatre points d'entrée de synchronisation ci-dessus.
    Route::middleware('company')->group(function (): void {
        foreach ([
            'clients' => ClientController::class,
            'employees' => EmployeeController::class,
            'garment-types' => GarmentTypeController::class,
            'measurement-fields' => MeasurementFieldController::class,
        ] as $uri => $controller) {
            Route::prefix($uri)->group(function () use ($controller, $uri): void {
                $name = str_replace('-', '_', $uri);
                Route::get('/', [$controller, 'index'])->name("api.v1.{$name}.index");
                Route::post('/', [$controller, 'store'])->name("api.v1.{$name}.store");
                Route::get('{id}', [$controller, 'show'])->name("api.v1.{$name}.show");
                Route::put('{id}', [$controller, 'update'])->name("api.v1.{$name}.update");
                Route::delete('{id}', [$controller, 'destroy'])->name("api.v1.{$name}.destroy");
                Route::post('{id}/restore', [$controller, 'restore'])->name("api.v1.{$name}.restore");
            });
        }
    });
});

// Password reset routes (public with rate limiting)
Route::middleware('throttle:6,1')->group(function (): void {
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
        ->name('password.email');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])
        ->name('password.reset');
});

/*
|--------------------------------------------------------------------------
| Console d'administration
|--------------------------------------------------------------------------
| Réservée aux super admins. L'authentification est publique mais fortement
| limitée en débit ; tout le reste passe par le garde `super_admin`.
*/
Route::prefix('admin')->group(function (): void {
    Route::middleware('throttle:admin-auth')->group(function (): void {
        Route::post('login', [AdminAuthController::class, 'login'])->name('api.v1.admin.login');
        Route::post('login/two-factor', [AdminAuthController::class, 'twoFactorChallenge'])
            ->name('api.v1.admin.login.two-factor');
    });

    Route::middleware(['auth:sanctum', 'super_admin', 'throttle:authenticated'])->group(function (): void {
        Route::get('overview', OverviewController::class)->name('api.v1.admin.overview');
        Route::get('me', [AdminAuthController::class, 'me'])->name('api.v1.admin.me');
        Route::post('logout', [AdminAuthController::class, 'logout'])->name('api.v1.admin.logout');
        Route::get('sessions', [AdminAuthController::class, 'sessions'])->name('api.v1.admin.sessions');
        Route::delete('sessions/{tokenId}', [AdminAuthController::class, 'revokeSession'])
            ->name('api.v1.admin.sessions.revoke');
        Route::get('login-history', [AdminAuthController::class, 'loginHistory'])
            ->name('api.v1.admin.login-history');

        Route::prefix('two-factor')->group(function (): void {
            Route::post('/', [TwoFactorController::class, 'enroll'])->name('api.v1.admin.2fa.enroll');
            Route::post('confirm', [TwoFactorController::class, 'confirm'])->name('api.v1.admin.2fa.confirm');
            Route::post('recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
                ->name('api.v1.admin.2fa.recovery');
            Route::delete('/', [TwoFactorController::class, 'disable'])->name('api.v1.admin.2fa.disable');
        });

        Route::prefix('companies')->group(function (): void {
            Route::get('/', [AdminCompanyController::class, 'index'])->name('api.v1.admin.companies.index');
            Route::get('{company}', [AdminCompanyController::class, 'show'])->name('api.v1.admin.companies.show');
            Route::put('{company}', [AdminCompanyController::class, 'update'])->name('api.v1.admin.companies.update');
            Route::put('{company}/diagnostic-settings', [AdminCompanyController::class, 'updateDiagnosticSettings'])
                ->name('api.v1.admin.companies.diagnostics.update');
            Route::delete('{company}/diagnostic-settings', [AdminCompanyController::class, 'resetDiagnosticSettings'])
                ->name('api.v1.admin.companies.diagnostics.reset');
        });

        Route::prefix('diagnostics')->group(function (): void {
            Route::get('bundles', [AdminDiagnosticController::class, 'bundles'])->name('api.v1.admin.bundles.index');
            Route::get('bundles/{bundle}', [AdminDiagnosticController::class, 'showBundle'])->name('api.v1.admin.bundles.show');
            Route::get('bundles/{bundle}/entry', [AdminDiagnosticController::class, 'bundleEntry'])->name('api.v1.admin.bundles.entry');
            Route::get('bundles/{bundle}/download', [AdminDiagnosticController::class, 'downloadBundle'])->name('api.v1.admin.bundles.download');
            Route::delete('bundles/{bundle}', [AdminDiagnosticController::class, 'destroyBundle'])->name('api.v1.admin.bundles.destroy');

            Route::get('commands', [AdminDiagnosticController::class, 'commands'])->name('api.v1.admin.commands.index');
            Route::post('commands', [AdminDiagnosticController::class, 'storeCommand'])->name('api.v1.admin.commands.store');
            Route::delete('commands/{command}', [AdminDiagnosticController::class, 'destroyCommand'])->name('api.v1.admin.commands.destroy');
        });
    });
});
