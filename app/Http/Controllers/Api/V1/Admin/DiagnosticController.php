<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Diagnostic\StoreDiagnosticCommandRequest;
use App\Models\DiagnosticBundle;
use App\Models\DiagnosticCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/** Inspection des rapports de diagnostic et pilotage des appareils. */
final class DiagnosticController extends ApiController
{
    /** Au-delà, on ne rend plus le contenu inline : la console propose le téléchargement. */
    private const MAX_INLINE_BYTES = 2 * 1024 * 1024;

    /** Extensions dont le contenu est lisible tel quel dans la console. */
    private const TEXT_EXTENSIONS = ['log', 'json', 'txt', 'csv', 'md'];

    // ── Bundles ───────────────────────────────────────────────────────────────

    public function bundles(Request $request): JsonResponse
    {
        $bundles = DiagnosticBundle::query()
            ->with('user:id,name,email')
            ->when(
                $request->filled('platform'),
                fn ($q) => $q->where('platform', $request->string('platform')->value())
            )
            ->when(
                $request->filled('user_id'),
                fn ($q) => $q->where('user_id', $request->integer('user_id'))
            )
            ->latest('sent_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return $this->success([
            'items' => array_map(
                fn (DiagnosticBundle $bundle): array => $this->bundleRow($bundle),
                $bundles->items()
            ),
            'meta' => [
                'total' => $bundles->total(),
                'page' => $bundles->currentPage(),
                'per_page' => $bundles->perPage(),
                'last_page' => $bundles->lastPage(),
            ],
        ]);
    }

    /** Métadonnées et contenu de l'archive, sans la décompresser sur disque. */
    public function showBundle(DiagnosticBundle $bundle): JsonResponse
    {
        $bundle->load('user:id,name,email');
        $path = Storage::disk('local')->path($bundle->file_path);

        if (! is_file($path)) {
            return $this->notFound("L'archive est introuvable sur le serveur.");
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return $this->error('Archive illisible ou corrompue.', 422);
        }

        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false || str_ends_with((string) $stat['name'], '/')) {
                continue;
            }

            $name = (string) $stat['name'];
            $entries[] = [
                'name' => $name,
                'size' => (int) $stat['size'],
                'readable' => $this->isReadable($name, (int) $stat['size']),
            ];
        }

        $zip->close();

        return $this->success($this->bundleRow($bundle) + ['entries' => $entries]);
    }

    /**
     * Rend le contenu d'un fichier de l'archive.
     *
     * Le nom demandé n'est jamais assemblé en chemin : il est comparé à la
     * liste réelle des entrées de l'archive, ce qui ferme la porte au « zip
     * slip » comme à la traversée de répertoire.
     */
    public function bundleEntry(Request $request, DiagnosticBundle $bundle): JsonResponse
    {
        $wanted = (string) $request->query('name', '');
        $path = Storage::disk('local')->path($bundle->file_path);

        if (! is_file($path)) {
            return $this->notFound("L'archive est introuvable sur le serveur.");
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return $this->error('Archive illisible ou corrompue.', 422);
        }

        $index = $zip->locateName($wanted);

        if ($index === false) {
            $zip->close();

            return $this->notFound("Ce fichier n'existe pas dans l'archive.");
        }

        $stat = $zip->statIndex($index);
        $size = $stat === false ? 0 : (int) $stat['size'];

        if (! $this->isReadable($wanted, $size)) {
            $zip->close();

            return $this->error(
                'Ce fichier ne peut pas être affiché ici. Téléchargez l\'archive.',
                422
            );
        }

        $content = $zip->getFromIndex($index, self::MAX_INLINE_BYTES);
        $zip->close();

        return $this->success([
            'name' => $wanted,
            'size' => $size,
            'content' => $content === false ? '' : $content,
        ]);
    }

    public function downloadBundle(DiagnosticBundle $bundle): StreamedResponse|JsonResponse
    {
        if (! Storage::disk('local')->exists($bundle->file_path)) {
            return $this->notFound("L'archive est introuvable sur le serveur.");
        }

        return Storage::disk('local')->download(
            $bundle->file_path,
            sprintf('coudi-diagnostic-%d.zip', $bundle->id)
        );
    }

    public function destroyBundle(DiagnosticBundle $bundle): JsonResponse
    {
        Storage::disk('local')->delete($bundle->file_path);
        $bundle->delete();

        return $this->success(message: 'Rapport supprimé.');
    }

    // ── Ordres ────────────────────────────────────────────────────────────────

    public function commands(Request $request): JsonResponse
    {
        $commands = DiagnosticCommand::query()
            ->withCount('acks')
            ->with('acks.user:id,name,email')
            ->when(
                $request->filled('company_id'),
                fn ($q) => $q->where('company_id', $request->string('company_id')->value())
            )
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return $this->success([
            'items' => array_map(
                fn (DiagnosticCommand $command): array => $this->commandRow($command),
                $commands->items()
            ),
            'meta' => [
                'total' => $commands->total(),
                'page' => $commands->currentPage(),
                'per_page' => $commands->perPage(),
                'last_page' => $commands->lastPage(),
            ],
        ]);
    }

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
        ], 'Ordre envoyé aux appareils.');
    }

    public function destroyCommand(DiagnosticCommand $command): JsonResponse
    {
        $command->delete();

        return $this->success(message: 'Ordre annulé.');
    }

    /** @return array<string, mixed> */
    private function commandRow(DiagnosticCommand $command): array
    {
        $acks = [];

        // Les vingt derniers suffisent à voir qui a appliqué l'ordre.
        foreach ($command->acks->sortByDesc('executed_at')->take(20) as $ack) {
            $acks[] = [
                'user' => $ack->user?->email,
                'status' => $ack->status,
                'message' => $ack->message,
                'executed_at' => $ack->executed_at?->toIso8601String(),
            ];
        }

        return [
            'id' => $command->id,
            'action' => $command->action,
            'file_name' => $command->file_name,
            'company_id' => $command->company_id,
            'created_at' => $command->created_at?->toIso8601String(),
            'acks_count' => $command->acks_count ?? 0,
            'acks' => $acks,
        ];
    }

    private function isReadable(string $name, int $size): bool
    {
        $extension = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return in_array($extension, self::TEXT_EXTENSIONS, true) && $size <= self::MAX_INLINE_BYTES;
    }

    /** @return array<string, mixed> */
    private function bundleRow(DiagnosticBundle $bundle): array
    {
        return [
            'id' => $bundle->id,
            'version' => $bundle->version,
            'platform' => $bundle->platform,
            'db_name' => $bundle->db_name,
            'file_size' => $bundle->file_size,
            'file_size_human' => $bundle->file_size_human,
            'sent_at' => $bundle->sent_at?->toIso8601String(),
            'user' => $bundle->user === null
                ? null
                : ['id' => $bundle->user->id, 'name' => $bundle->user->name, 'email' => $bundle->user->email],
        ];
    }
}
