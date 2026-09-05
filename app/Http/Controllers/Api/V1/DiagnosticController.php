<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Diagnostic\StoreDiagnosticBundleRequest;
use App\Http\Requests\Api\V1\Diagnostic\UpdateDiagnosticSettingsRequest;
use App\Models\DiagnosticBundle;
use App\Models\DiagnosticSetting;
use Illuminate\Http\JsonResponse;
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
    public function showSettings(): JsonResponse
    {
        // Les utilisateurs ne sont pas encore rattachés à une entreprise : on
        // sert donc le défaut global. Dès que le lien existera, passer ici
        // l'identifiant d'entreprise suffira à activer le réglage par atelier.
        $setting = DiagnosticSetting::resolveFor(null);

        return $this->success($this->settingsPayload($setting));
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
            ]
        );

        return $this->success(
            $this->settingsPayload($setting),
            'Réglages de journalisation mis à jour.'
        );
    }

    /** @return array<string, mixed> */
    private function settingsPayload(DiagnosticSetting $setting): array
    {
        return [
            'log_rotation' => $setting->log_rotation,
            'log_retention' => $setting->log_retention,
            'company_id' => $setting->company_id,
        ];
    }
}
