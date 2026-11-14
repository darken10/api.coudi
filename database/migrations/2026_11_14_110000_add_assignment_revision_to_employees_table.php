<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compteur de visibilité par employé.
 *
 * Le périmètre de synchronisation est mémorisé par appareil. Quand les
 * affectations d'un employé changent, sa signature de périmètre doit changer
 * aussi : sans cela, une commande retirée resterait indéfiniment dans le
 * téléphone, le serveur ne pouvant pas la renvoyer comme suppression
 * puisqu'elle est simplement devenue invisible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'assignment_revision')) {
                $table->unsignedInteger('assignment_revision')->default(0)->after('user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('assignment_revision');
        });
    }
};
