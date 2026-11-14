<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturation par atelier et journal des actions d'administration.
 *
 * Un abonnement est unique par atelier ; une facture appartient à un
 * abonnement. Le journal d'administration, lui, trace les décisions prises
 * depuis la console (suspension, changement de plan, modification d'atelier).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('company_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('plan', 30)->default('free');
                $table->string('status', 20)->default('active');
                $table->timestamp('trial_ends_at')->nullable();
                $table->timestamp('current_period_ends_at')->nullable();
                $table->boolean('cancel_at_period_end')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('subscription_id')->constrained()->cascadeOnDelete();
                $table->string('invoice_no', 40);
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('currency', 10)->default('FCFA');
                $table->string('status', 20)->default('open');
                $table->timestamp('due_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();

                $table->index(['subscription_id', 'status']);
            });
        }

        if (! Schema::hasTable('admin_actions')) {
            Schema::create('admin_actions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 80);
                $table->foreignUuid('company_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_actions');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
    }
};
