<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Diagnostic\AckDiagnosticCommandRequest;
use App\Http\Requests\Api\V1\Diagnostic\StoreDiagnosticBundleRequest;
use App\Http\Requests\Api\V1\Diagnostic\StoreDiagnosticCommandRequest;
use App\Http\Requests\Api\V1\Diagnostic\UpdateDiagnosticSettingsRequest;
use App\Models\DiagnosticBundle;
use App\Models\DiagnosticCommand;
use App\Models\DiagnosticCommandAck;
use App\Models\DiagnosticSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class DiagnosticController extends ApiController
{
    /**
     * Reçoit le bundle de diagnostic (ZIP logs + SQLite) depuis l'app mobile.
     *
     * POST /api/v1/diagnostics/bundle
     */
    public function storeBundle(StoreDiagnosticBundleRequest $request): JsonResponse
    {
        $file = $request->file('bundle');
        $userId = $request->user()->id;

        $filename = sprintf(
            'bundle_%d_%s.zip',
            $userId,
            now()->format('Y-m-d_His')
        );

        $path = $file->storeAs(
            "diagnostics/bundles/{$userId}",
            $filename,
            'local'
        );

        $bundle = DiagnosticBundle::query()->create([
            'user_id' => $userId,
            'version' => $request->version,
            'sent_at' => $request->sent_at ? Carbon::parse($request->sent_at) : now(),
            'db_name' => $request->db_name,
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'platform' => $request->platform,
        ]);

        return $this->created(
            [
                'id' => $bundle->id,
                'file_size' => $bundle->file_size_human,
            ],
            'Bundle de diagnostic reçu avec succès.'
        );
    }

    /**
     * Réglages de journalisation appliqués par l'app mobile.
     *
     * GET /api/v1/diagnostics/settings
     */
    public function showSettings(Request $request): JsonResponse
    {
        // Les utilisateurs ne sont pas encore rattachés à une entreprise : on
        // sert donc le défaut global. Dès que le lien existera, passer ici
        // l'identifiant d'entreprise suffira à activer le réglage par atelier.
        $user = $request->user();

        if ($user === null) {
            return $this->unauthorized();
        }

        $setting = DiagnosticSetting::resolveFor(null);
        $command = DiagnosticCommand::pendingFor($user->id, null);

        return $this->success($this->settingsPayload($setting, $command));
    }

    /**
     * Crée ou met à jour les réglages d'une entreprise — ou le défaut global
     * quand `company_id` est absent.
     *
     * PUT /api/v1/diagnostics/settings
     */
    public function updateSettings(UpdateDiagnosticSettingsRequest $request): JsonResponse
    {
        $setting = DiagnosticSetting::query()->updateOrCreate(
            ['company_id' => $request->company_id],
            [
                'log_rotation' => $request->log_rotation,
                'log_retention' => $request->log_retention,
                'log_level' => $request->log_level,
                'log_api_calls' => $request->log_api_calls,
                'log_api_bodies' => $request->log_api_bodies,
            ]
        );

        return $this->success(
            $this->settingsPayload($setting),
            'Réglages de journalisation mis à jour.'
        );
    }

    /**
     * Émet un ordre à destination des appareils (console web).
     *
     * POST /api/v1/diagnostics/commands
     */
    public function storeCommand(StoreDiagnosticCommandRequest $request): JsonResponse
    {
        $command = DiagnosticCommand::query()->create([
            'company_id' => $request->company_id,
            'action' => $request->action,
            'file_name' => $request->file_name,
        ]);

        return $this->created([
            'id' => $command->id,
            'action' => $command->action,
            'file_name' => $command->file_name,
            'company_id' => $command->company_id,
        ], 'Ordre de diagnostic enregistré.');
    }

    /**
     * L'appareil signale qu'il a exécuté l'ordre — ou pourquoi il a échoué.
     *
     * POST /api/v1/diagnostics/commands/{command}/ack
     */
    public function ackCommand(AckDiagnosticCommandRequest $request, DiagnosticCommand $command): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->unauthorized();
        }

        $ack = DiagnosticCommandAck::query()->updateOrCreate(
            ['command_id' => $command->id, 'user_id' => $user->id],
            [
                'status' => $request->status,
                'message' => $request->message,
                'executed_at' => now(),
            ]
        );

        return $this->success([
            'command_id' => $ack->command_id,
            'status' => $ack->status,
        ], 'Ordre acquitté.');
    }

    /** @return array<string, mixed> */
    private function settingsPayload(DiagnosticSetting $setting, ?DiagnosticCommand $command = null): array
    {
        return [
            'log_rotation' => $setting->log_rotation,
            'log_retention' => $setting->log_retention,
            'log_level' => $setting->log_level,
            'log_api_calls' => (bool) $setting->log_api_calls,
            'log_api_bodies' => (bool) $setting->log_api_bodies,
            'company_id' => $setting->company_id,
            'command' => $command === null ? null : [
                'id' => $command->id,
                'action' => $command->action,
                'file_name' => $command->file_name,
            ],
        ];
    }
}
