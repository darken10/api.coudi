<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Requests\Api\V1\Sync\PullRequest;
use App\Http\Requests\Api\V1\Sync\PushRequest;
use App\Sync\PushOutcome;
use App\Sync\RevisionSequence;
use App\Sync\SyncPuller;
use App\Sync\SyncPusher;
use App\Sync\SyncRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les quatre points d'entrée de la synchronisation.
 *
 * Toutes les routes exigent un atelier (`X-Company-Id`) et un appareil
 * (`X-Device-Id`) : la synchronisation n'a de sens qu'entre une installation
 * précise et un atelier précis.
 */
final class SyncController extends ApiController
{
    use ResolvesTenant;

    public function __construct(
        private readonly SyncPuller $puller,
        private readonly SyncPusher $pusher,
    ) {}

    /**
     * Premier chargement après connexion : l'appareil part de zéro.
     *
     * Même mécanique que `pull`, à trois détails près — le curseur démarre à
     * zéro, la réponse annonce le volume total pour que l'écran d'accueil
     * puisse afficher une progression, et la fin du chargement est datée.
     *
     * POST /api/v1/sync/bootstrap
     */
    public function bootstrap(PullRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $device = $this->device($request);
        $state = $device->syncStateFor($company);

        $result = $this->puller->pull($company, $request->since(), $request->entities(), $request->limit());

        $state->fill(['last_pulled_revision' => $result['cursor']]);

        if (! $result['has_more']) {
            $state->fill(['bootstrapped_at' => now()]);
        }

        $state->save();

        return $this->success($result + ['totals' => $this->totals($company->getKey())]);
    }

    /**
     * Rattrapage : tout ce qui a changé au-delà du curseur de l'appareil.
     *
     * POST /api/v1/sync/pull
     */
    public function pull(PullRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $device = $this->device($request);

        $result = $this->puller->pull($company, $request->since(), $request->entities(), $request->limit());

        $device->syncStateFor($company)->update(['last_pulled_revision' => $result['cursor']]);

        return $this->success($result);
    }

    /**
     * Remontée de la file d'attente locale.
     *
     * Un envoi ne renvoie jamais d'erreur globale pour une ligne fautive : la
     * réponse est un verdict par opération. Sur une liaison instable, un lot
     * bloqué en entier par une seule ligne condamnerait la synchronisation.
     *
     * POST /api/v1/sync/push
     */
    public function push(PushRequest $request): JsonResponse
    {
        $company = $this->company($request);

        if (! $this->canWrite($request)) {
            return $this->forbidden('Votre rôle sur cet atelier ne permet pas la modification.');
        }

        $results = $this->pusher->push($company, $this->device($request), $request->ops());

        return $this->success([
            'results' => $results,
            'summary' => $this->summarize($results),
            'server_revision' => RevisionSequence::current($company->getKey()),
        ]);
    }

    /**
     * Où en est cet appareil, et que sait faire ce serveur.
     *
     * GET /api/v1/sync/status
     */
    public function status(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $state = $this->device($request)->syncStateFor($company);

        return $this->success([
            'company_id' => $company->getKey(),
            'server_revision' => RevisionSequence::current($company->getKey()),
            'device_revision' => $state->last_pulled_revision,
            'bootstrapped_at' => $state->bootstrapped_at?->toIso8601String(),
            'last_pushed_at' => $state->last_pushed_at?->toIso8601String(),
            'behind' => max(0, RevisionSequence::current($company->getKey()) - $state->last_pulled_revision),
            'entities' => SyncRegistry::keys(),
            'can_write' => $this->canWrite($request),
        ]);
    }

    /**
     * Volume par entité — sert la barre de progression du premier chargement.
     *
     * @return array<string, int>
     */
    private function totals(string $companyId): array
    {
        $totals = [];

        foreach (SyncRegistry::all() as $key => $entity) {
            $totals[$key] = $entity->newModel()->newQuery()
                ->where($key === 'companies' ? 'id' : 'company_id', $companyId)
                ->count();
        }

        return $totals;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, int>
     */
    private function summarize(array $results): array
    {
        $summary = array_fill_keys(array_column(PushOutcome::cases(), 'value'), 0);

        foreach ($results as $result) {
            $status = (string) ($result['status'] ?? '');

            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        return $summary;
    }
}
