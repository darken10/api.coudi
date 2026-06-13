<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Diagnostic\StoreDiagnosticBundleRequest;
use App\Models\DiagnosticBundle;
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
        $file   = $request->file('bundle');
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
            'user_id'   => $userId,
            'version'   => $request->version,
            'sent_at'   => $request->sent_at ? Carbon::parse($request->sent_at) : now(),
            'db_name'   => $request->db_name,
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'platform'  => $request->platform,
        ]);

        return $this->created(
            [
                'id'        => $bundle->id,
                'file_size' => $bundle->file_size_human,
            ],
            'Bundle de diagnostic reçu avec succès.'
        );
    }
}
