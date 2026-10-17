<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Transfert du binaire ; la ligne media_assets continue de passer par push/pull. */
final class SyncMediaController extends ApiController
{
    use ResolvesTenant;

    public function upload(Request $request, string $asset): JsonResponse
    {
        if (! $this->canWrite($request)) {
            return $this->forbidden('Votre rôle sur cet atelier ne permet pas la modification.');
        }

        $media = $this->media($request, $asset);
        $request->validate(['file' => ['required', 'file', 'max:102400']]);
        $file = $request->file('file');
        if ($file === null) {
            return $this->error('Fichier manquant.', 422);
        }

        $mime = (string) $file->getMimeType();
        $prefix = match ($media->type) {
            'image' => 'image/',
            'video' => 'video/',
            'audio' => 'audio/',
            default => '',
        };
        if ($prefix === '' || ! str_starts_with($mime, $prefix)) {
            return $this->error('Type de fichier incompatible avec ce média.', 422);
        }

        $checksum = hash_file('sha256', $file->getRealPath());
        if ($checksum === false) {
            return $this->error('Impossible de vérifier le fichier.', 422);
        }

        if ($media->storage_path !== null && Storage::disk('local')->exists($media->storage_path)) {
            if ($media->checksum !== $checksum) {
                return $this->error('Ce média possède déjà un autre fichier.', 409);
            }

            return $this->success(['id' => $media->getKey(), 'checksum' => $checksum]);
        }

        $path = Storage::disk('local')->putFileAs(
            "sync-media/{$this->company($request)->syncCompanyId()}",
            $file,
            (string) $media->getKey(),
        );
        if ($path === false) {
            return $this->error('Stockage du fichier impossible.', 500);
        }

        try {
            DB::transaction(function () use ($media, $path, $checksum, $mime): void {
                $media->forceFill([
                    'storage_path' => $path,
                    'checksum' => $checksum,
                    'uploaded_at' => now(),
                    'mime_type' => $mime,
                ])->save();
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        return $this->success(['id' => $media->getKey(), 'checksum' => $checksum]);
    }

    public function download(Request $request, string $asset): StreamedResponse|JsonResponse
    {
        $media = $this->media($request, $asset);
        if ($media->storage_path === null || ! Storage::disk('local')->exists($media->storage_path)) {
            return $this->notFound('Fichier média indisponible. Réessayez après la synchronisation de l’appareil source.');
        }

        return Storage::disk('local')->response(
            $media->storage_path,
            $media->file_name ?: (string) $media->getKey(),
            ['Content-Type' => $media->mime_type ?: 'application/octet-stream'],
        );
    }

    private function media(Request $request, string $asset): MediaAsset
    {
        return MediaAsset::query()
            ->where('company_id', $this->company($request)->syncCompanyId())
            ->whereKey($asset)
            ->firstOrFail();
    }
}
