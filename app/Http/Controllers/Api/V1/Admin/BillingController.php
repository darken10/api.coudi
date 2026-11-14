<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AdminAction;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Facturation par atelier : abonnement et factures.
 *
 * Chaque décision est tracée dans le journal d'audit. Le paiement d'une
 * facture peut reclasser l'abonnement en `active`.
 */
final class BillingController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->value();
        $status = $request->string('status')->trim()->value();

        if ($status !== '' && ! in_array($status, Subscription::STATUSES, true)) {
            return $this->validationError(['status' => ['État inconnu.']]);
        }

        $subscriptions = Subscription::query()
            ->with('company:id,name,status')
            ->withCount('invoices')
            ->when($search !== '', function ($query) use ($search): void {
                $query->whereHas('company', fn ($inner) => $inner->where('name', 'like', "%{$search}%"));
            })
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderBy('created_at', 'desc')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return $this->success([
            'items' => $subscriptions->getCollection()
                ->map(fn (Subscription $subscription): array => $this->row($subscription))
                ->values()
                ->all(),
            'meta' => [
                'total' => $subscriptions->total(),
                'page' => $subscriptions->currentPage(),
                'per_page' => $subscriptions->perPage(),
                'last_page' => $subscriptions->lastPage(),
            ],
        ]);
    }

    public function show(Subscription $subscription): JsonResponse
    {
        $subscription->load('company:id,name,status')->loadCount('invoices');

        $invoices = $subscription->invoices()->latest('created_at')->get()
            ->map(fn (Invoice $invoice): array => $this->invoiceRow($invoice))
            ->values()
            ->all();

        return $this->success($this->row($subscription) + ['invoices' => $invoices]);
    }

    public function update(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(Subscription::PLANS)],
            'status' => ['required', Rule::in(Subscription::STATUSES)],
        ]);

        $subscription->update($data);

        /** @var User|null $admin */
        $admin = $request->user();
        AdminAction::record('subscription.updated', $admin, $subscription->company, null, $data);

        return $this->success($this->row($subscription), 'Abonnement mis à jour.');
    }

    public function storeInvoice(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'invoice_no' => ['required', 'string', 'max:40'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'due_at' => ['nullable', 'date'],
        ]);

        $invoice = $subscription->invoices()->create([
            'invoice_no' => $data['invoice_no'],
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'FCFA',
            'due_at' => $data['due_at'] ?? null,
            'status' => Invoice::STATUS_OPEN,
        ]);

        /** @var User|null $admin */
        $admin = $request->user();
        AdminAction::record('invoice.created', $admin, $subscription->company, null, [
            'invoice_no' => $invoice->invoice_no,
            'amount' => $invoice->amount,
        ]);

        return $this->created($this->invoiceRow($invoice), 'Facture créée.');
    }

    public function updateInvoice(Request $request, Subscription $subscription, Invoice $invoice): JsonResponse
    {
        if ($invoice->subscription_id !== $subscription->getKey()) {
            return $this->notFound('Facture introuvable.');
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(Invoice::STATUSES)],
        ]);

        $invoice->update([
            'status' => $data['status'],
            'paid_at' => $data['status'] === Invoice::STATUS_PAID ? now() : $invoice->paid_at,
        ]);

        if ($data['status'] === Invoice::STATUS_PAID && $subscription->status === Subscription::STATUS_PAST_DUE) {
            $subscription->update(['status' => Subscription::STATUS_ACTIVE]);
        }

        /** @var User|null $admin */
        $admin = $request->user();
        AdminAction::record('invoice.updated', $admin, $subscription->company, null, [
            'invoice_no' => $invoice->invoice_no,
            'status' => $data['status'],
        ]);

        return $this->success($this->invoiceRow($invoice), 'Facture mise à jour.');
    }

    /** @return array<string, mixed> */
    private function row(Subscription $subscription): array
    {
        return [
            'id' => $subscription->getKey(),
            'company_id' => $subscription->company_id,
            'company_name' => $subscription->company?->name,
            'company_status' => $subscription->company?->status,
            'plan' => $subscription->plan,
            'status' => $subscription->status,
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'current_period_ends_at' => $subscription->current_period_ends_at?->toIso8601String(),
            'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            'invoices_count' => $subscription->invoices_count ?? $subscription->invoices()->count(),
            'created_at' => $subscription->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceRow(Invoice $invoice): array
    {
        return [
            'id' => $invoice->getKey(),
            'invoice_no' => $invoice->invoice_no,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'status' => $invoice->status,
            'due_at' => $invoice->due_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'created_at' => $invoice->created_at?->toIso8601String(),
        ];
    }
}
