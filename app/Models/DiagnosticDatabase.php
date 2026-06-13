<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int         $id
 * @property int         $user_id
 * @property string|null $version
 * @property Carbon|null $sent_at
 * @property string      $db_name
 * @property string      $file_path
 * @property int         $file_size
 * @property string|null $platform
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 */
#[Fillable([
    'user_id',
    'version',
    'sent_at',
    'db_name',
    'file_path',
    'file_size',
    'platform',
])]
final class DiagnosticDatabase extends Model
{
    protected $casts = [
        'sent_at'   => 'datetime',
        'file_size' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Taille formatée en Ko/Mo. */
    public function getFileSizeHumanAttribute(): string
    {
        if ($this->file_size < 1024) {
            return "{$this->file_size} o";
        }
        if ($this->file_size < 1024 * 1024) {
            return round($this->file_size / 1024, 1).' Ko';
        }

        return round($this->file_size / (1024 * 1024), 2).' Mo';
    }

    /** URL de téléchargement temporaire (disque local). */
    public function downloadUrl(): string
    {
        return Storage::disk('local')->url($this->file_path);
    }
}
