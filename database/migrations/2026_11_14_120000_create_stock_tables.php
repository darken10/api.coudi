<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock de matières premières.
 *
 * `stock_items` est le catalogue des matières (pagne, fil, accessoires…),
 * `stock_movements` l'historique des entrées et sorties. Le stock courant est
 * la somme des mouvements : rien n'est jamais écrasé, chaque correction est un
 * nouveau mouvement, ce qui reste lisible hors ligne et en cas de conflit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('reference')->nullable();
            $table->string('category')->nullable();
            $table->string('unit')->default('piece');
            $table->decimal('low_stock_threshold', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $this->syncColumns($table);
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_item_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // in | out
            $table->decimal('quantity', 12, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestamp('moved_at')->nullable();
            $this->syncColumns($table);

            $table->index(['stock_item_id', 'moved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_items');
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
