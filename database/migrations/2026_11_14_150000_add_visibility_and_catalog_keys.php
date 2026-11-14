<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visibilité par champ + clé de catalogue stable.
 *
 * - `garment_type_fields.is_required` : un champ associé à un vêtement peut
 *   être exigé lors de la prise de mesure.
 * - `garment_type_fields.is_visible` : un champ associé peut être masqué de
 *   la fiche de mesure sans être retiré de la configuration.
 * - `catalog_key` (garment_types et measurement_fields) : identifiant stable
 *   du catalogue global. Permet au backfill de reconnaître un vêtement même
 *   renommé par l'atelier, et donc de ne pas le recréer en doublon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('garment_type_fields', function (Blueprint $table): void {
            if (! Schema::hasColumn('garment_type_fields', 'is_required')) {
                $table->boolean('is_required')->default(false)->after('sort_order');
            }
            if (! Schema::hasColumn('garment_type_fields', 'is_visible')) {
                $table->boolean('is_visible')->default(true)->after('is_required');
            }
        });

        Schema::table('garment_types', function (Blueprint $table): void {
            if (! Schema::hasColumn('garment_types', 'catalog_key')) {
                $table->string('catalog_key', 100)->nullable()->after('category')->index();
            }
        });

        Schema::table('measurement_fields', function (Blueprint $table): void {
            if (! Schema::hasColumn('measurement_fields', 'catalog_key')) {
                $table->string('catalog_key', 100)->nullable()->after('name')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('garment_type_fields', function (Blueprint $table): void {
            $table->dropColumn(['is_required', 'is_visible']);
        });

        Schema::table('garment_types', function (Blueprint $table): void {
            $table->dropColumn('catalog_key');
        });

        Schema::table('measurement_fields', function (Blueprint $table): void {
            $table->dropColumn('catalog_key');
        });
    }
};
