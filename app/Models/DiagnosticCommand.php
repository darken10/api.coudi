<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ordre ponctuel adressé aux appareils depuis la console web.
 *
 * Un ordre dont `company_id` est NULL s'adresse à tout le parc. Chaque appareil
 * l'exécute une fois et l'acquitte : c'est l'acquittement, et non l'ordre, qui
 * porte l'état « appliqué ».
 */
/**
 * @property int $id
 * @property string $action
 * @property string|null $file_name
 * @property string|null $company_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DiagnosticCommandAck> $acks
 * @property-read int|null $acks_count
 */
final class DiagnosticCommand extends Model
{
    /** Crée un nouveau fichier de log portant le nom demandé. */
    public const ACTION_NEW_LOG_FILE = 'new_log_file';

    /** Revient à la rotation normale après un fichier nommé. */
    public const ACTION_RESUME_ROTATION = 'resume_rotation';

    /** @var list<string> */
    public const ACTIONS = [self::ACTION_NEW_LOG_FILE, self::ACTION_RESUME_ROTATION];

    protected $fillable = [
        'company_id',
        'action',
        'file_name',
    ];

    /**
     * Dernier ordre que cet utilisateur n'a pas encore acquitté.
     *
     * Les ordres visant l'entreprise priment sur ceux visant tout le parc.
     */
    public static function pendingFor(int $userId, ?string $companyId = null): ?self
    {
        return self::query()
            ->where(function ($query) use ($companyId): void {
                $query->whereNull('company_id');
                if ($companyId !== null) {
                    $query->orWhere('company_id', $companyId);
                }
            })
            ->whereDoesntHave('acks', fn ($query) => $query->where('user_id', $userId))
            ->orderByDesc('company_id')
            ->orderByDesc('id')
            ->first();
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<DiagnosticCommandAck, $this> */
    public function acks(): HasMany
    {
        return $this->hasMany(DiagnosticCommandAck::class, 'command_id');
    }
}
