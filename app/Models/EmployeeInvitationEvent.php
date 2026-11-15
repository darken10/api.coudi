<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Journal d'une invitation : chaque étape réelle, dans l'ordre.
 *
 * Ne contient jamais de code ni de mot de passe — seulement le type
 * d'événement, le canal, l'auteur et un contexte non sensible.
 *
 * @property int $id
 * @property string $type
 * @property string|null $channel
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 */
final class EmployeeInvitationEvent extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'invitation_id',
        'company_id',
        'employee_id',
        'type',
        'channel',
        'actor_user_id',
        'ip',
        'meta',
    ];

    /** @return BelongsTo<EmployeeInvitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(EmployeeInvitation::class, 'invitation_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }
}
