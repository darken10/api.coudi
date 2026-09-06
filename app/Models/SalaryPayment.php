<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versement de salaire sur une période.
 */
final class SalaryPayment extends Model
{
    use BelongsToCompany;
    use Syncable;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'amount',
        'period_start',
        'period_end',
        'paid_at',
        'notes',
    ];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'period_start' => 'date',
            'period_end' => 'date',
            'paid_at' => 'datetime',
        ];
    }
}
