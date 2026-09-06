<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Miroir serveur du dossier client et de la commande (schéma SQLite mobile,
 * versions 10 à 16) : mesures, commandes, notes, paiements, catalogue de
 * modèles et médias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_measurements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('garment_type_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('field_id')->constrained('measurement_fields')->cascadeOnDelete();
            $table->string('value')->default('');
            $table->timestamp('measured_at')->nullable();
            $this->syncColumns($table);

            $table->unique(['client_id', 'garment_type_id', 'field_id']);
        });

        Schema::create('model_galleries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('cover_uri')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $this->syncColumns($table);
        });

        Schema::create('design_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('gallery_id')->nullable()->constrained('model_galleries')->nullOnDelete();
            $table->foreignUuid('garment_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->decimal('base_price', 12, 2)->nullable();
            $table->string('cover_uri')->nullable();
            $table->string('tags')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('usage_count')->default(0);
            $this->syncColumns($table);
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained()->restrictOnDelete();
            $table->string('order_code', 40);
            $table->foreignUuid('garment_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('design_model_id')->nullable()->constrained('design_models')->nullOnDelete();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('deposit', 12, 2)->default(0);
            $table->string('status', 20)->default('new');
            $table->foreignUuid('assigned_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $this->syncColumns($table);

            // Deux appareils hors ligne génèrent le même code : le serveur
            // renumérote et renvoie le code retenu dans le résultat du push.
            $table->unique(['company_id', 'order_code']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('order_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $this->syncColumns($table);
        });

        Schema::create('order_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->text('note')->nullable();
            $this->syncColumns($table);
        });

        Schema::create('order_measurements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('field_id')->constrained('measurement_fields')->cascadeOnDelete();
            $table->string('value')->default('');
            $this->syncColumns($table);

            $table->unique(['order_id', 'field_id']);
        });

        /*
         * Médias : la ligne décrit le fichier, elle ne le transporte pas.
         * `uri` est le chemin local de l'appareil d'origine — inexploitable
         * ailleurs ; `storage_path` et `checksum` ne sont renseignés qu'une fois
         * le binaire téléversé sur le canal dédié.
         */
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('owner_type', 20);   // order | model | note
            $table->uuid('owner_id');
            $table->string('category', 20)->default('model');
            $table->string('type', 20);         // image | video | audio
            $table->string('uri')->nullable();
            $table->string('storage_path')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('caption')->nullable();
            $table->integer('sort_order')->default(0);
            $this->syncColumns($table);

            $table->index(['owner_type', 'owner_id', 'category', 'sort_order']);
        });
    }

    public function down(): void
    {
        foreach ([
            'media_assets', 'order_measurements', 'order_payments', 'order_notes',
            'orders', 'design_models', 'model_galleries', 'client_measurements',
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
