<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string|null $version
 * @property string|null $platform
 * @property string|null $db_name
 * @property string $file_path
 * @property int $file_size
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property-read User|null $user
 * @property-read string $file_size_human
 */
final class DiagnosticBundle extends Model
{
    protected $fillable = [
        'user_id',
        'version',
        'sent_at',
        'db_name',
        'file_path',
        'file_size',
        'platform',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Taille lisible (ex : "1.2 MB"). */
    public function getFileSizeHumanAttribute(): string
    {
        $bytes = $this->file_size;
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1024 ** 2) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1024 ** 2, 1).' MB';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'file_size' => 'integer',
        ];
    }
}
