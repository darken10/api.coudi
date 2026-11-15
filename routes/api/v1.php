<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\V1\Admin\AuditController;
use App\Http\Controllers\Api\V1\Admin\BillingController;
use App\Http\Controllers\Api\V1\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Api\V1\Admin\DiagnosticController as AdminDiagnosticController;
use App\Http\Controllers\Api\V1\Admin\EmployeeInvitationController as AdminEmployeeInvitationController;
use App\Http\Controllers\Api\V1\Admin\OrderAssignmentController as AdminOrderAssignmentController;
use App\Http\Controllers\Api\V1\Admin\OverviewController;
use App\Http\Controllers\Api\V1\Admin\TwoFactorController;
use App\Http\Controllers\Api\V1\Admin\UsersController;
use App\Http\Controllers\Api\V1\Admin\WorkshopResourceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\DiagnosticController;
use App\Http\Controllers\Api\V1\EmployeeActivationController;
use App\Http\Controllers\Api\V1\OtpController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\Workshop\ClientController;
use App\Http\Controllers\Api\V1\Workshop\EmployeeController;
use App\Http\Controllers\Api\V1\Workshop\EmployeeInvitationController;
use App\Http\Controllers\Api\V1\Workshop\OrderAssignmentController;
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

// Activation d'un compte employé : numéro + code, puis mot de passe.
// Public, borné par IP ici et par numéro / par invitation dans le service.
Route::middleware('throttle:activation')->group(function (): void {
    Route::post('auth/activation/verify', [EmployeeActivationController::class, 'verify'])
        ->name('api.v1.activation.verify');
    Route::post('auth/activation/complete', [EmployeeActivationController::class, 'complete'])
        ->name('api.v1.activation.complete');
});

// Réinitialisation par OTP (public, débit fortement limité)
Route::middleware('throttle:6,1')->group(function (): void {
    Route::post('auth/otp/request', [OtpController::class, 'request'])->name('api.v1.otp.request');
    Route::post('auth/otp/verify', [OtpController::class, 'verify'])->name('api.v1.otp.verify');
    Route::post('auth/password/reset', [OtpController::class, 'reset'])->name('api.v1.otp.reset');
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
        Route::post('media/{asset}/file', [App\Http\Controllers\Api\V1\SyncMediaController::class, 'upload'])->name('api.v1.sync.media.upload');
        Route::get('media/{asset}/file', [App\Http\Controllers\Api\V1\SyncMediaController::class, 'download'])->name('api.v1.sync.media.download');
    });

    // CRUD REST du référentiel — console web. Le mobile n'y touche pas : il ne
    // parle qu'aux quatre points d'entrée de synchronisation ci-dessus.
    Route::middleware('company')->group(function (): void {
        Route::get('employee-invitations', [EmployeeInvitationController::class, 'overview'])
            ->name('api.v1.employee-invitations.overview');
        Route::get('employee-invitations/channels', [EmployeeInvitationController::class, 'channels'])
            ->name('api.v1.employee-invitations.channels');

        Route::prefix('employees/{employee}/invitations')->group(function (): void {
            Route::get('/', [EmployeeInvitationController::class, 'index'])
                ->name('api.v1.employee-invitations.index');
            Route::post('/', [EmployeeInvitationController::class, 'store'])
                ->name('api.v1.employee-invitations.store');
            Route::delete('{invitation}', [EmployeeInvitationController::class, 'destroy'])
                ->name('api.v1.employee-invitations.destroy');
        });

        Route::prefix('orders/{order}/assignments')->group(function (): void {
            Route::get('/', [OrderAssignmentController::class, 'index'])
                ->name('api.v1.order-assignments.index');
            Route::post('/', [OrderAssignmentController::class, 'store'])
                ->name('api.v1.order-assignments.store');
            Route::delete('{employee}', [OrderAssignmentController::class, 'destroy'])
                ->name('api.v1.order-assignments.destroy');
        });

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

        Route::prefix('users')->group(function (): void {
            Route::get('/', [UsersController::class, 'index'])->name('api.v1.admin.users.index');
            Route::get('{user}', [UsersController::class, 'show'])->name('api.v1.admin.users.show');
            Route::put('{user}', [UsersController::class, 'update'])->name('api.v1.admin.users.update');
        });

        Route::prefix('subscriptions')->group(function (): void {
            Route::get('/', [BillingController::class, 'index'])->name('api.v1.admin.subscriptions.index');
            Route::get('{subscription}', [BillingController::class, 'show'])->name('api.v1.admin.subscriptions.show');
            Route::put('{subscription}', [BillingController::class, 'update'])->name('api.v1.admin.subscriptions.update');
            Route::post('{subscription}/invoices', [BillingController::class, 'storeInvoice'])
                ->name('api.v1.admin.subscriptions.invoices.store');
            Route::put('{subscription}/invoices/{invoice}', [BillingController::class, 'updateInvoice'])
                ->name('api.v1.admin.subscriptions.invoices.update');
        });

        Route::prefix('audit')->group(function (): void {
            Route::get('logins', [AuditController::class, 'logins'])->name('api.v1.admin.audit.logins');
            Route::get('actions', [AuditController::class, 'actions'])->name('api.v1.admin.audit.actions');
        });

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
            Route::put('{company}/restriction', [AdminCompanyController::class, 'updateRestriction'])
                ->name('api.v1.admin.companies.restriction.update');

            // Le super administrateur peut corriger tout le dossier métier
            // d'un atelier depuis le web. Les écritures restent synchronisables.
            Route::get('{company}/workspace', [WorkshopResourceController::class, 'overview'])
                ->name('api.v1.admin.companies.workspace');

            // Comptes employés : invitation, suivi, révocation.
            Route::prefix('{company}/employees/{employee}/invitations')->group(function (): void {
                Route::get('/', [AdminEmployeeInvitationController::class, 'index'])
                    ->name('api.v1.admin.employee-invitations.index');
                Route::post('/', [AdminEmployeeInvitationController::class, 'store'])
                    ->name('api.v1.admin.employee-invitations.store');
                Route::delete('{invitation}', [AdminEmployeeInvitationController::class, 'destroy'])
                    ->name('api.v1.admin.employee-invitations.destroy');
            });

            // Affectations multiples : source de vérité de la visibilité mobile.
            Route::prefix('{company}/orders/{order}/assignments')->group(function (): void {
                Route::get('/', [AdminOrderAssignmentController::class, 'index'])
                    ->name('api.v1.admin.order-assignments.index');
                Route::post('/', [AdminOrderAssignmentController::class, 'store'])
                    ->name('api.v1.admin.order-assignments.store');
                Route::delete('{employee}', [AdminOrderAssignmentController::class, 'destroy'])
                    ->name('api.v1.admin.order-assignments.destroy');
            });
            Route::prefix('{company}/resources/{entity}')->group(function (): void {
                Route::get('/', [WorkshopResourceController::class, 'index'])
                    ->name('api.v1.admin.workshop-resources.index');
                Route::post('/', [WorkshopResourceController::class, 'store'])
                    ->name('api.v1.admin.workshop-resources.store');
                Route::get('{id}', [WorkshopResourceController::class, 'show'])
                    ->name('api.v1.admin.workshop-resources.show');
                Route::put('{id}', [WorkshopResourceController::class, 'update'])
                    ->name('api.v1.admin.workshop-resources.update');
                Route::delete('{id}', [WorkshopResourceController::class, 'destroy'])
                    ->name('api.v1.admin.workshop-resources.destroy');
                Route::post('{id}/restore', [WorkshopResourceController::class, 'restore'])
                    ->name('api.v1.admin.workshop-resources.restore');
            });
        });

        Route::prefix('diagnostics')->group(function (): void {
            Route::get('bundles', [AdminDiagnosticController::class, 'bundles'])->name('api.v1.admin.bundles.index');
            Route::get('bundles/{bundle}', [AdminDiagnosticController::class, 'showBundle'])->name('api.v1.admin.bundles.show');
            Route::get('bundles/{bundle}/entry', [AdminDiagnosticController::class, 'bundleEntry'])->name('api.v1.admin.bundles.entry');
            Route::get('bundles/{bundle}/database/tables', [AdminDiagnosticController::class, 'databaseTables'])->name('api.v1.admin.bundles.database.tables');
            Route::get('bundles/{bundle}/database/rows', [AdminDiagnosticController::class, 'databaseRows'])->name('api.v1.admin.bundles.database.rows');
            Route::get('bundles/{bundle}/download', [AdminDiagnosticController::class, 'downloadBundle'])->name('api.v1.admin.bundles.download');
            Route::delete('bundles/{bundle}', [AdminDiagnosticController::class, 'destroyBundle'])->name('api.v1.admin.bundles.destroy');

            Route::get('commands', [AdminDiagnosticController::class, 'commands'])->name('api.v1.admin.commands.index');
            Route::post('commands', [AdminDiagnosticController::class, 'storeCommand'])->name('api.v1.admin.commands.store');
            Route::delete('commands/{command}', [AdminDiagnosticController::class, 'destroyCommand'])->name('api.v1.admin.commands.destroy');
        });
    });
});
