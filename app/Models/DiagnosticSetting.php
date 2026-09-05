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
/**
 * @property string|null $company_id
 * @property string $log_rotation
 * @property int $log_retention
 * @property string $log_level
 * @property bool $log_api_calls
 * @property bool $log_api_bodies
 * @property-read Company|null $company
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

    /** Niveaux acceptés, du plus bavard au plus silencieux. */
    public const LEVELS = ['debug', 'info', 'warn', 'error', 'silent'];

    public const DEFAULT_LEVEL = 'info';

    public const DEFAULT_LOG_API_CALLS = true;

    public const DEFAULT_LOG_API_BODIES = false;

    protected $fillable = [
        'company_id',
        'log_rotation',
        'log_retention',
        'log_level',
        'log_api_calls',
        'log_api_bodies',
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
                'log_level' => self::DEFAULT_LEVEL,
                'log_api_calls' => self::DEFAULT_LOG_API_CALLS,
                'log_api_bodies' => self::DEFAULT_LOG_API_BODIES,
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
            'log_api_calls' => 'boolean',
            'log_api_bodies' => 'boolean',
        ];
    }
}
