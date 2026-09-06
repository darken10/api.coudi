<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Socle multi-locataire : l'atelier (`companies`) est le locataire, les comptes
 * y accèdent via un pivot porteur de rôle, et chaque installation mobile est
 * enregistrée comme appareil.
 *
 * `companies.sync_revision` est le compteur monotone du locataire : chaque
 * écriture synchronisable y prélève un numéro, et c'est ce numéro que les
 * appareils utilisent comme curseur de rattrapage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name');
            $table->string('owner_name')->nullable()->after('description');
            $table->timestamp('archived_at')->nullable()->after('status');
            // Compteur du locataire : la source des numéros de révision.
            $table->unsignedBigInteger('sync_revision')->default(0);
            // Révision de la ligne elle-même : le profil de l'atelier se réplique
            // comme n'importe quelle autre entité.
            $table->unsignedBigInteger('revision')->default(0);
            $table->uuid('last_device_id')->nullable();
        });

        // Un compte peut gérer plusieurs ateliers ; un atelier peut être partagé
        // entre plusieurs comptes (le patron et ses tailleurs).
        Schema::create('company_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('owner'); // owner | manager | tailor
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
            $table->index('user_id');
        });

        // Une ligne par installation. Le curseur de synchronisation vit ici :
        // deux téléphones du même compte rattrapent indépendamment.
        Schema::create('devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('platform', 20)->nullable();  // ios | android
            $table->string('app_version', 40)->nullable();
            $table->string('os_version', 40)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        // Curseur de rattrapage par (appareil, atelier) : un appareil qui gère
        // trois ateliers avance dans chacun à son rythme.
        Schema::create('device_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_pulled_revision')->default(0);
            $table->timestamp('bootstrapped_at')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'company_id']);
        });

        // Registre d'idempotence : un push rejoué après coupure réseau relit sa
        // réponse au lieu de dupliquer l'écriture.
        Schema::create('sync_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->string('entity', 60);
            $table->string('op', 10);      // create | update | delete
            $table->string('status', 20);  // applied | conflict | deferred | rejected
            $table->json('result')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'operation_id']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_operations');
        Schema::dropIfExists('device_sync_states');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('company_user');

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn([
                'description', 'owner_name', 'archived_at',
                'sync_revision', 'revision', 'last_device_id',
            ]);
        });
    }
};
