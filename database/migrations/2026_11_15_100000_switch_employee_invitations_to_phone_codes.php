<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invitation des employés par téléphone et code à 6 chiffres.
 *
 * Remplace le lien à jeton : les invitations encore ouvertes sous l'ancien
 * format sont révoquées (le patron renverra un code). Aucune donnée n'est
 * supprimée ; les colonnes de l'ancien format restent lisibles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_invitations', function (Blueprint $table): void {
            if (! Schema::hasColumn('employee_invitations', 'channel')) {
                $table->string('channel', 20)->nullable()->after('phone'); // sms | whatsapp
            }
            if (! Schema::hasColumn('employee_invitations', 'code_hash')) {
                // HMAC du code : jamais le code en clair.
                $table->string('code_hash', 64)->nullable()->after('token_hash');
            }
            if (! Schema::hasColumn('employee_invitations', 'attempts')) {
                $table->unsignedTinyInteger('attempts')->default(0)->after('code_hash');
                $table->unsignedTinyInteger('max_attempts')->default(5)->after('attempts');
            }
            if (! Schema::hasColumn('employee_invitations', 'send_status')) {
                // pending | sent | failed : ce que le fournisseur a répondu.
                $table->string('send_status', 20)->default('pending')->after('channel');
                $table->string('send_error', 40)->nullable()->after('send_status');
                $table->string('provider_message_id')->nullable()->after('send_error');
                $table->timestamp('sent_at')->nullable()->after('provider_message_id');
                // Renseignée seulement si le fournisseur signale la livraison.
                $table->timestamp('delivered_at')->nullable()->after('sent_at');
            }
            if (! Schema::hasColumn('employee_invitations', 'code_verified_at')) {
                $table->timestamp('code_verified_at')->nullable()->after('expires_at');
                // Ticket à usage unique remis après le code, pour choisir le mot de passe.
                $table->string('ticket_hash', 64)->nullable()->unique()->after('code_verified_at');
                $table->timestamp('ticket_expires_at')->nullable()->after('ticket_hash');
                $table->string('activation_ip', 45)->nullable()->after('ticket_expires_at');
                $table->string('activation_device_id', 100)->nullable()->after('activation_ip');
            }
        });

        // Le jeton de lien n'existe plus pour les nouvelles invitations.
        if (Schema::hasColumn('employee_invitations', 'token_hash')) {
            Schema::table('employee_invitations', function (Blueprint $table): void {
                $table->string('token_hash', 64)->nullable()->change();
            });
        }

        if (! Schema::hasIndex('employee_invitations', 'employee_invitations_phone_open_index')) {
            Schema::table('employee_invitations', function (Blueprint $table): void {
                $table->index(['phone', 'accepted_at', 'revoked_at'], 'employee_invitations_phone_open_index');
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('users', 'password_set_at')) {
                // Null tant que l'employé n'a pas choisi lui-même son mot de passe.
                $table->timestamp('password_set_at')->nullable()->after('password');
            }
        });

        if (! Schema::hasTable('employee_invitation_events')) {
            Schema::create('employee_invitation_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('invitation_id')->nullable()->constrained('employee_invitations')->cascadeOnDelete();
                $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('employee_id')->nullable()->constrained()->nullOnDelete();
                // created | sent | send_failed | resent | revoked | code_failed |
                // code_expired | code_blocked | code_verified | password_set | activated
                $table->string('type', 30);
                $table->string('channel', 20)->nullable();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('ip', 45)->nullable();
                // Contexte non sensible uniquement : jamais de code ni de mot de passe.
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['company_id', 'created_at']);
                $table->index(['invitation_id', 'type']);
            });
        }

        // Les liens encore ouverts ne peuvent plus être utilisés.
        DB::table('employee_invitations')
            ->whereNull('code_hash')
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_invitation_events');

        Schema::table('users', function (Blueprint $table): void {
            foreach (['phone_verified_at', 'password_set_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('employee_invitations', function (Blueprint $table): void {
            $table->dropIndex('employee_invitations_phone_open_index');
            $table->dropUnique(['ticket_hash']);
            $table->dropColumn([
                'channel', 'code_hash', 'attempts', 'max_attempts', 'send_status', 'send_error',
                'provider_message_id', 'sent_at', 'delivered_at', 'code_verified_at', 'ticket_hash',
                'ticket_expires_at', 'activation_ip', 'activation_device_id',
            ]);
        });
    }
};
