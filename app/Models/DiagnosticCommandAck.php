<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Trace de l'exécution d'un ordre par un appareil donné. */
/**
 * @property int $command_id
 * @property string $status
 * @property string|null $message
 * @property \Illuminate\Support\Carbon|null $executed_at
 * @property-read DiagnosticCommand|null $command
 * @property-read User|null $user
 */
final class DiagnosticCommandAck extends Model
{
    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_DONE, self::STATUS_FAILED];

    protected $fillable = [
        'command_id',
        'user_id',
        'status',
        'message',
        'executed_at',
    ];

    /** @return BelongsTo<DiagnosticCommand, $this> */
    public function command(): BelongsTo
    {
        return $this->belongsTo(DiagnosticCommand::class, 'command_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['executed_at' => 'datetime'];
    }
}
