<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int         $id
 * @property int         $user_id
 * @property string|null $version
 * @property Carbon|null $sent_at
 * @property string|null $file_content
 * @property array|null  $memory_entries
 * @property string|null $platform
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 */
#[Fillable([
    'user_id',
    'version',
    'sent_at',
    'file_content',
    'memory_entries',
    'platform',
])]
final class DiagnosticLog extends Model
{
    protected $casts = [
        'sent_at'        => 'datetime',
        'memory_entries' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
