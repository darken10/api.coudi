<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/**
 * Volet « atelier » des réglages mobiles.
 *
 * Seul ce qui a du sens sur un autre téléphone se réplique : devise, unité,
 * préfixe de commande, étiquettes. L'imprimante AirPrint, le code PIN et la
 * journalisation restent locaux à l'appareil.
 */
final class CompanySetting extends Model
{
    use BelongsToCompany;
    use Syncable;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'currency',
        'measure_unit',
        'default_deadline_days',
        'order_prefix',
        'label_format',
        'label_show_price',
        'label_show_tailor',
        'label_show_phone',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'default_deadline_days' => 'integer',
            'label_show_price' => 'boolean',
            'label_show_tailor' => 'boolean',
            'label_show_phone' => 'boolean',
        ];
    }
}
