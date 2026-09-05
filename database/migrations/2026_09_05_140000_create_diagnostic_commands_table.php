<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ordres ponctuels adressés à l'app (ex. « ouvre un fichier de log nommé
        // incident-4712 »), émis depuis la console web.
        Schema::create('diagnostic_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('company_id')->nullable()->index(); // NULL = tout le parc
            $table->string('action', 30);
            $table->string('file_name', 60)->nullable();
            $table->timestamps();
        });

        // Une ligne par appareil ayant exécuté l'ordre : la console web sait
        // ainsi qui l'a appliqué et qui ne l'a pas encore vu.
        Schema::create('diagnostic_command_acks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('command_id')->constrained('diagnostic_commands')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('done'); // done | failed
            $table->text('message')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
            $table->unique(['command_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_command_acks');
        Schema::dropIfExists('diagnostic_commands');
    }
};
