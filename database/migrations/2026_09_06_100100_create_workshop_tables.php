<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Miroir serveur du référentiel de l'atelier (schéma SQLite mobile, versions 4
 * à 9) : personnel, catalogue de types de vêtement, champs de mesure et paie.
 *
 * Trois colonnes reviennent partout et portent la synchronisation :
 *  - `revision`        : numéro prélevé sur le compteur du locataire ;
 *  - `last_device_id`  : appareil auteur de la dernière écriture ;
 *  - `deleted_at`      : pierre tombale — on ne supprime jamais en dur, sinon
 *                        un appareil hors ligne ne peut pas apprendre l'effacement.
 *
 * `company_id` est dupliqué sur les tables filles (journaux, paies, tarifs).
 * La redondance est assumée : c'est la clé d'isolation du locataire et la
 * première colonne de l'index de rattrapage, on ne peut pas la remonter par
 * jointure à chaque `pull`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('role', 40)->default('other');
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->decimal('salary', 12, 2)->nullable();
            $table->string('status', 20)->default('active');
            $table->date('hired_at')->nullable();
            $table->string('payment_type', 20)->default('monthly'); // monthly | piece
            $table->decimal('rate_amount', 12, 2)->nullable();
            $this->syncColumns($table);
        });

        Schema::create('garment_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('complexity', 20)->default('simple');
            $table->decimal('base_price', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $this->syncColumns($table);
        });

        Schema::create('measurement_fields', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category', 60)->default('Autre');
            $table->integer('sort_order')->default(0);
            $this->syncColumns($table);
        });

        // Pivot doté de son propre identifiant : sans identité de ligne, un
        // appareil ne peut ni référencer ni retirer une association.
        Schema::create('garment_type_fields', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('garment_type_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('field_id')->constrained('measurement_fields')->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $this->syncColumns($table);

            $table->unique(['garment_type_id', 'field_id']);
        });

        Schema::create('employee_piece_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('garment_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('rate', 12, 2)->default(0);
            $this->syncColumns($table);

            // Le mobile fait déjà un upsert sur ce couple : on l'impose ici pour
            // que deux appareils ne créent pas deux tarifs concurrents.
            $table->unique(['employee_id', 'garment_type_id']);
        });

        Schema::create('work_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('garment_type_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('quantity')->default(1);
            $table->date('work_date');
            $table->text('notes')->nullable();
            $this->syncColumns($table);

            $table->index(['company_id', 'work_date']);
        });

        Schema::create('salary_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $this->syncColumns($table);
        });

        /*
         * Volet « atelier » des réglages mobiles : devise, unité, préfixe de
         * commande, étiquettes. Le volet « appareil » (imprimante AirPrint, PIN,
         * journalisation) reste local — il n'a aucun sens sur un autre téléphone.
         */
        Schema::create('company_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('currency', 10)->default('FCFA');
            $table->string('measure_unit', 10)->default('cm');
            $table->unsignedInteger('default_deadline_days')->default(7);
            $table->string('order_prefix', 10)->default('AB');
            $table->string('label_format', 20)->default('sticker90x50');
            $table->boolean('label_show_price')->default(true);
            $table->boolean('label_show_tailor')->default(false);
            $table->boolean('label_show_phone')->default(true);
            $this->syncColumns($table);
        });
    }

    public function down(): void
    {
        foreach ([
            'company_settings', 'salary_payments', 'work_logs', 'employee_piece_rates',
            'garment_type_fields', 'measurement_fields', 'garment_types', 'employees',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** Colonnes de synchronisation communes à toute table répliquée. */
    private function syncColumns(Blueprint $table): void
    {
        $table->unsignedBigInteger('revision')->default(0);
        $table->uuid('last_device_id')->nullable();
        $table->timestamps();
        $table->softDeletes();

        $table->index(['company_id', 'revision']);
    }
};
