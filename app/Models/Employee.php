<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use App\Models\Contracts\Replicable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Personnel de l'atelier. `payment_type` décide de la paie : au mois
 * (`salary`) ou à la pièce (barème dans `employee_piece_rates`).
 */
final class Employee extends Model implements Replicable
{
    use BelongsToCompany;
    use Syncable;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
        'role',
        'phone',
        'email',
        'whatsapp',
        'photo_uri',
        'salary',
        'status',
        'hired_at',
        'payment_type',
        'rate_amount',
    ];

    /** Le lien de compte reste une affaire serveur : jamais dans une réponse. */
    protected $hidden = ['user_id'];

    /** Le compte de connexion lié à cet employé, s'il a été activé. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<OrderAssignment, $this> */
    public function orderAssignments(): HasMany
    {
        return $this->hasMany(OrderAssignment::class);
    }

    /** @return HasMany<EmployeePieceRate, $this> */
    public function pieceRates(): HasMany
    {
        return $this->hasMany(EmployeePieceRate::class);
    }

    /** @return HasMany<WorkLog, $this> */
    public function workLogs(): HasMany
    {
        return $this->hasMany(WorkLog::class);
    }

    /** @return HasMany<SalaryPayment, $this> */
    public function salaryPayments(): HasMany
    {
        return $this->hasMany(SalaryPayment::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'salary' => 'float',
            'rate_amount' => 'float',
            'hired_at' => 'date',
        ];
    }
}
