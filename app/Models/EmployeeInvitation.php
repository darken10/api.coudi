<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Invitation à activer un compte employé par téléphone.
 *
 * Le code à 6 chiffres n'existe en clair que le temps de l'envoi : la base ne
 * garde que son HMAC, lié à l'identifiant de l'invitation (un code ne peut
 * donc servir à aucune autre invitation, ni à un autre atelier).
 *
 * Le statut n'est pas une colonne : il se déduit des horodatages, chacun posé
 * par un événement réel (réponse du fournisseur, saisie du code…).
 *
 * @property string $id
 * @property string $company_id
 * @property string $employee_id
 * @property int $user_id
 * @property string|null $phone
 * @property string|null $channel
 * @property string $send_status
 * @property string|null $send_error
 * @property string|null $code_hash
 * @property int $attempts
 * @property int $max_attempts
 * @property Carbon $expires_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $code_verified_at
 * @property string|null $ticket_hash
 * @property Carbon|null $ticket_expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 */
final class EmployeeInvitation extends Model
{
    use HasUuids;

    public const SEND_PENDING = 'pending';

    public const SEND_SENT = 'sent';

    public const SEND_FAILED = 'failed';

    public const STATUS_PENDING_SEND = 'pending_send';

    public const STATUS_SEND_FAILED = 'send_failed';

    public const STATUS_AWAITING_ACTIVATION = 'awaiting_activation';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_PASSWORD_REQUIRED = 'password_required';

    public const STATUS_ACTIVATED = 'activated';

    public const STATUS_REVOKED = 'revoked';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'company_id',
        'employee_id',
        'user_id',
        'email',
        'phone',
        'channel',
        'send_status',
        'send_error',
        'provider_message_id',
        'sent_at',
        'delivered_at',
        'code_hash',
        'attempts',
        'max_attempts',
        'expires_at',
        'code_verified_at',
        'ticket_hash',
        'ticket_expires_at',
        'activation_ip',
        'activation_device_id',
        'accepted_at',
        'revoked_at',
        'created_by',
    ];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<EmployeeInvitationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(EmployeeInvitationEvent::class, 'invitation_id');
    }

    /**
     * Statut vu par le patron. L'ordre des tests compte : un compte activé
     * reste « activé » même si le code aurait expiré depuis.
     */
    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => self::STATUS_ACTIVATED,
            $this->revoked_at !== null => self::STATUS_REVOKED,
            $this->code_verified_at !== null => self::STATUS_PASSWORD_REQUIRED,
            $this->send_status === self::SEND_FAILED => self::STATUS_SEND_FAILED,
            $this->send_status === self::SEND_PENDING => self::STATUS_PENDING_SEND,
            $this->expires_at->isPast() => self::STATUS_EXPIRED,
            $this->attempts >= $this->max_attempts => self::STATUS_BLOCKED,
            default => self::STATUS_AWAITING_ACTIVATION,
        };
    }

    /** Le code peut-il encore être saisi ? */
    public function acceptsCode(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->code_verified_at === null
            && $this->code_hash !== null
            && $this->send_status === self::SEND_SENT
            && $this->attempts < $this->max_attempts
            && $this->expires_at->isFuture();
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'code_verified_at' => 'datetime',
            'ticket_expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
