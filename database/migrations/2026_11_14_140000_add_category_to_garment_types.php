<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute une catégorie aux types de vêtements (Homme, Femme, Enfant, Autre…).
 *
 * La catégorie ne sert qu'au regroupement dans l'interface : chaque atelier
 * possède ses propres types, elle est donc répliquée comme le reste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('garment_types', function (Blueprint $table): void {
            if (! Schema::hasColumn('garment_types', 'category')) {
                $table->string('category', 40)->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('garment_types', function (Blueprint $table): void {
            $table->dropColumn('category');
        });
    }
};
