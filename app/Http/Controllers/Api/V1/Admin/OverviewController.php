<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Client;
use App\Models\Company;
use App\Models\DiagnosticBundle;
use App\Models\DiagnosticCommand;
use App\Models\DiagnosticCommandAck;
use App\Models\DiagnosticSetting;
use App\Models\LoginAudit;
use Illuminate\Http\JsonResponse;

/**
 * Vue d'ensemble de la console.
 *
 * Construite autour de ce qui demande une action — journalisation laissée
 * verbeuse, ordres en échec, connexions refusées — plutôt qu'autour de
 * compteurs qu'on regarde sans jamais en faire quoi que ce soit.
 */
final class OverviewController extends ApiController
{
    public function __invoke(): JsonResponse
    {
        return $this->success([
            'companies' => [
                'total' => Company::query()->count(),
                'active' => Company::query()->where('status', Company::STATUS_ACTIVE)->count(),
                'suspended' => Company::query()->where('status', Company::STATUS_SUSPENDED)->count(),
                'overridden' => DiagnosticSetting::query()->whereNotNull('company_id')->count(),
            ],
            'clients_total' => Client::query()->count(),
            'bundles' => [
                'total' => DiagnosticBundle::query()->count(),
                'last_7_days' => DiagnosticBundle::query()->where('sent_at', '>=', now()->subDays(7))->count(),
                'latest' => $this->latestBundles(),
            ],
            'commands' => [
                'total' => DiagnosticCommand::query()->count(),
                'unapplied' => DiagnosticCommand::query()->doesntHave('acks')->count(),
            ],
            'attention' => [
                'verbose_logging' => $this->verboseLogging(),
                'failed_acks' => $this->failedAcks(),
                'failed_logins_24h' => LoginAudit::query()
                    ->whereIn('status', [
                        LoginAudit::BAD_PASSWORD,
                        LoginAudit::BAD_TWO_FACTOR,
                        LoginAudit::FORBIDDEN,
                        LoginAudit::LOCKED,
                    ])
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
            ],
        ]);
    }

    /**
     * Ateliers dont la journalisation est restée bavarde.
     *
     * `debug` remplit le stockage du téléphone et le contenu des requêtes
     * comporte des données clients : ces réglages servent le temps d'un
     * diagnostic, pas en permanence.
     *
     * @return list<array<string, mixed>>
     */
    private function verboseLogging(): array
    {
        $rows = [];

        $settings = DiagnosticSetting::query()
            ->whereNotNull('company_id')
            ->where(function ($query): void {
                $query->where('log_level', 'debug')->orWhere('log_api_bodies', true);
            })
            ->with('company:id,name')
            ->get();

        foreach ($settings as $setting) {
            $rows[] = [
                'company_id' => $setting->company_id,
                'company_name' => $setting->company?->name,
                'log_level' => $setting->log_level,
                'log_api_bodies' => (bool) $setting->log_api_bodies,
            ];
        }

        // Le défaut global concerne tout le parc : il compte double.
        $global = DiagnosticSetting::query()->whereNull('company_id')->first();

        if ($global && ($global->log_level === 'debug' || $global->log_api_bodies)) {
            array_unshift($rows, [
                'company_id' => null,
                'company_name' => null,
                'log_level' => $global->log_level,
                'log_api_bodies' => (bool) $global->log_api_bodies,
            ]);
        }

        return $rows;
    }

    /**
     * Ordres qu'un appareil n'a pas réussi à appliquer.
     *
     * @return list<array<string, mixed>>
     */
    private function failedAcks(): array
    {
        $rows = [];

        $acks = DiagnosticCommandAck::query()
            ->where('status', DiagnosticCommandAck::STATUS_FAILED)
            ->with(['command:id,action,file_name', 'user:id,email'])
            ->latest('executed_at')
            ->limit(10)
            ->get();

        foreach ($acks as $ack) {
            $rows[] = [
                'command_id' => $ack->command_id,
                'action' => $ack->command?->action,
                'file_name' => $ack->command?->file_name,
                'user' => $ack->user?->email,
                'message' => $ack->message,
                'executed_at' => $ack->executed_at?->toIso8601String(),
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function latestBundles(): array
    {
        $rows = [];

        $bundles = DiagnosticBundle::query()
            ->with('user:id,name,email')
            ->latest('sent_at')
            ->limit(5)
            ->get();

        foreach ($bundles as $bundle) {
            $rows[] = [
                'id' => $bundle->id,
                'platform' => $bundle->platform,
                'version' => $bundle->version,
                'file_size_human' => $bundle->file_size_human,
                'sent_at' => $bundle->sent_at?->toIso8601String(),
                'user' => $bundle->user?->email,
            ];
        }

        return $rows;
    }
}
