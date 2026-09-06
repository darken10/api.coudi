<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use App\Models\Contracts\Replicable;
use Illuminate\Database\Eloquent\Model;

/**
 * Métadonnées d'un média — jamais le binaire.
 *
 * `uri` est le chemin local de l'appareil d'origine, inexploitable ailleurs :
 * `storage_path` et `checksum` ne sont renseignés qu'une fois le fichier
 * téléversé sur le canal dédié.
 */
final class MediaAsset extends Model implements Replicable
{
    use BelongsToCompany;
    use Syncable;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'owner_type',
        'owner_id',
        'category',
        'type',
        'uri',
        'storage_path',
        'checksum',
        'uploaded_at',
        'file_name',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'duration_ms',
        'caption',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
