<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_settings', function (Blueprint $table): void {
            $table->id();
            // NULL = réglage par défaut appliqué à toutes les entreprises.
            $table->foreignUuid('company_id')->nullable()->unique();
            $table->string('log_rotation', 20)->default('monthly'); // daily | weekly | monthly
            $table->unsignedSmallInteger('log_retention')->default(6);
            $table->timestamps();
        });

        // Ligne de défaut global : l'endpoint a toujours quelque chose à servir.
        DB::table('diagnostic_settings')->insert([
            'company_id' => null,
            'log_rotation' => 'monthly',
            'log_retention' => 6,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_settings');
    }
};
