<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réglages de journalisation poussés vers l'app mobile.
 *
 * Une ligne dont `company_id` est NULL fait office de défaut global : elle est
 * servie tant qu'aucune ligne propre à l'entreprise n'existe.
 */
final class DiagnosticSetting extends Model
{
    public const ROTATION_DAILY = 'daily';

    public const ROTATION_WEEKLY = 'weekly';

    public const ROTATION_MONTHLY = 'monthly';

    /** @var list<string> */
    public const ROTATIONS = [
        self::ROTATION_DAILY,
        self::ROTATION_WEEKLY,
        self::ROTATION_MONTHLY,
    ];

    public const DEFAULT_ROTATION = self::ROTATION_MONTHLY;

    public const DEFAULT_RETENTION = 6;

    protected $fillable = [
        'company_id',
        'log_rotation',
        'log_retention',
    ];

    /**
     * Réglage applicable : celui de l'entreprise, sinon le défaut global,
     * sinon une instance non persistée portant les valeurs par défaut.
     */
    public static function resolveFor(?string $companyId = null): self
    {
        $setting = $companyId !== null
            ? self::query()->where('company_id', $companyId)->first()
            : null;

        return $setting
            ?? self::query()->whereNull('company_id')->first()
            ?? new self([
                'log_rotation' => self::DEFAULT_ROTATION,
                'log_retention' => self::DEFAULT_RETENTION,
            ]);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'log_retention' => 'integer',
        ];
    }
}
