<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sent_at'   => 'datetime',
            'file_size' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Taille lisible (ex : "1.2 MB"). */
    public function getFileSizeHumanAttribute(): string
    {
        $bytes = $this->file_size;
        if ($bytes < 1024) return "{$bytes} B";
        if ($bytes < 1024 ** 2) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1024 ** 2, 1) . ' MB';
    }
}
