<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le modèle Company déclarait déjà ces champs en `fillable`, mais la table
     * ne contenait que son identifiant : toute écriture échouait.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('name')->nullable()->after('id');
            $table->string('address')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('logo_uri')->nullable();
            $table->string('status', 20)->default('active')->index(); // active | suspended
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn(['name', 'address', 'phone', 'email', 'website', 'logo_uri', 'status']);
        });
    }
};
