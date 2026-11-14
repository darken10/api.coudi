<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Comptes employés, invitations, réinitialisation OTP et affectations.
 *
 * Un employé existait jusqu'ici comme simple ligne de personnel, sans compte :
 * il ne pouvait donc ni se connecter, ni retrouver les commandes qu'on lui
 * confiait. Cette migration relie l'employé à un compte `users`, ajoute le
 * parcours d'invitation (jeton à usage unique), la réinitialisation par OTP et
 * la table d'affectations multiple avec son historique.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Idempotence : MySQL ne journalise pas le DDL, une migration peut
         * s'être appliquée à moitié. Chaque ajout est donc gardé pour pouvoir
         * être rejoué sans échouer sur « duplicate column ».
         */
        Schema::table('users', function (Blueprint $table): void {
            // Un employé peut n'avoir qu'un téléphone : l'email devient
            // facultatif, et le téléphone devient un identifiant de connexion.
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 40)->nullable()->unique()->after('email');
            }
            if (! Schema::hasColumn('users', 'whatsapp')) {
                $table->string('whatsapp', 40)->nullable()->after('phone');
            }
            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status', 20)->default('active')->index()->after('password');
            }
        });

        Schema::table('employees', function (Blueprint $table): void {
            // Le compte qui incarne cet employé. Une ligne de personnel peut
            // exister sans compte (saisie administrative), d'où le nullable.
            // Pas d'unicité : une même personne peut travailler dans deux
            // ateliers et posséder une fiche dans chacun.
            if (! Schema::hasColumn('employees', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('company_id')
                    ->constrained('users')->nullOnDelete();
                $table->index(['user_id', 'company_id']);
            }
            if (! Schema::hasColumn('employees', 'whatsapp')) {
                $table->string('whatsapp', 40)->nullable()->after('phone');
            }
            if (! Schema::hasColumn('employees', 'photo_uri')) {
                $table->string('photo_uri')->nullable()->after('email');
            }
        });

        Schema::table('company_settings', function (Blueprint $table): void {
            // TRUE : un employé ne voit que les commandes qui lui sont
            // affectées. FALSE : il voit toutes les commandes de l'atelier.
            if (! Schema::hasColumn('company_settings', 'employee_restricted_orders')) {
                $table->boolean('employee_restricted_orders')->default(false)
                    ->after('label_show_phone');
            }
        });

        Schema::table('device_sync_states', function (Blueprint $table): void {
            // Empreinte du périmètre de visibilité du dernier pull. Un
            // changement impose une reprise à zéro pour purger le local.
            if (! Schema::hasColumn('device_sync_states', 'last_scope')) {
                $table->string('last_scope', 80)->nullable()->after('last_pulled_revision');
            }
        });

        if (! Schema::hasTable('employee_invitations')) {
            Schema::create('employee_invitations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('token_hash', 64)->unique();
                $table->string('email')->nullable();
                $table->string('phone', 40)->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['company_id', 'employee_id']);
                $table->index(['employee_id', 'accepted_at']);
            });
        }

        if (! Schema::hasTable('password_reset_otps')) {
            Schema::create('password_reset_otps', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('identifier', 255);
                $table->string('channel', 20); // email | sms | whatsapp
                // Le code n'est jamais stocké en clair : seul son hash permet de
                // vérifier une saisie sans qu'une fuite de base le livre.
                $table->string('code_hash', 64);
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->unsignedTinyInteger('max_attempts')->default(5);
                $table->timestamp('expires_at');
                $table->timestamp('consumed_at')->nullable();
                $table->string('ip', 45)->nullable();
                $table->timestamps();

                $table->index(['identifier', 'channel', 'consumed_at']);
                $table->index('expires_at');
            });
        }

        if (! Schema::hasTable('order_assignments')) {
            Schema::create('order_assignments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
                // Auteur de l'affectation : le patron, le responsable ou le
                // secrétaire — jamais l'employé lui-même en V1.
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('removed_at')->nullable();
                $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 20)->default('active'); // active | removed
                $table->text('notes')->nullable();

                // Colonnes de synchronisation communes.
                $table->unsignedBigInteger('revision')->default(0);
                $table->uuid('last_device_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['company_id', 'revision']);
                $table->index(['order_id', 'status']);
                $table->index(['employee_id', 'status']);
            });
        }

        // Reprise de l'existant : toute commande déjà confiée à un employé
        // reçoit une affectation active. Sans cela, la nouvelle table serait
        // vide et les employés perdraient leurs commandes au déploiement.
        if (Schema::hasTable('order_assignments')) {
            DB::table('orders')
                ->whereNotNull('assigned_employee_id')
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('order_assignments')
                        ->whereColumn('order_assignments.order_id', 'orders.id');
                })
                ->orderBy('id')
                ->chunk(200, function ($orders): void {
                    $now = now();

                    $rows = [];

                    foreach ($orders as $order) {
                        $rows[] = [
                            'id' => (string) Str::uuid(),
                            'company_id' => $order->company_id,
                            'order_id' => $order->id,
                            'employee_id' => $order->assigned_employee_id,
                            'assigned_at' => $order->created_at ?? $now,
                            'status' => 'active',
                            'revision' => max(1, (int) ($order->revision ?? 1)),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if ($rows !== []) {
                        DB::table('order_assignments')->insert($rows);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_assignments');
        Schema::dropIfExists('password_reset_otps');
        Schema::dropIfExists('employee_invitations');

        Schema::table('device_sync_states', function (Blueprint $table): void {
            $table->dropColumn('last_scope');
        });

        Schema::table('company_settings', function (Blueprint $table): void {
            $table->dropColumn('employee_restricted_orders');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['whatsapp', 'photo_uri']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->change();
            $table->dropUnique(['phone']);
            $table->dropColumn(['phone', 'whatsapp', 'status']);
        });
    }
};
