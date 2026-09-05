<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diagnostic_settings', function (Blueprint $table): void {
            $table->string('log_level', 10)->default('info')->after('log_retention');
            $table->boolean('log_api_calls')->default(true)->after('log_level');
            // Les corps de requête peuvent contenir des données personnelles :
            // désactivé par défaut, à n'activer que le temps d'un diagnostic.
            $table->boolean('log_api_bodies')->default(false)->after('log_api_calls');
        });
    }

    public function down(): void
    {
        Schema::table('diagnostic_settings', function (Blueprint $table): void {
            $table->dropColumn(['log_level', 'log_api_calls', 'log_api_bodies']);
        });
    }
};
