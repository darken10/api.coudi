<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `clients` préexiste à la synchronisation : on l'aligne sur le socle commun
 * (compteur de révision, appareil auteur) sans toucher au reste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->unsignedBigInteger('revision')->default(0);
            $table->uuid('last_device_id')->nullable();
            $table->index(['company_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'revision']);
            $table->dropColumn(['revision', 'last_device_id']);
        });
    }
};
