<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use App\Models\Contracts\Replicable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Affectation d'une commande à un employé.
 *
 * Plusieurs lignes actives peuvent désigner la même commande : l'atelier
 * répartit le travail entre plusieurs tailleurs. Une désaffectation ne
 * supprime pas la ligne — elle passe en `removed`, ce qui conserve
 * l'historique exigé par le métier.
 *
 * L'affectation est répliquée comme une entité à part entière : c'est elle qui
 * fait apparaître la commande sur l'appareil de l'employé, sans quoi le serveur
 * et le téléphone ne partageraient aucune information d'attribution.
 */
final class OrderAssignment extends Model implements Replicable
{
    use BelongsToCompany;
    use Syncable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REMOVED = 'removed';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'order_id',
        'employee_id',
        'assigned_by',
        'assigned_at',
        'removed_at',
        'removed_by',
        'status',
        'notes',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }
}
