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
 * Commande passée par un client.
 *
 * `order_code` est unique par atelier et généré côté appareil : deux
 * téléphones hors ligne produisent fatalement le même « AB-0042 ». Le serveur
 * ne rejette pas pour autant — il renumérote et renvoie le code retenu dans le
 * résultat du push, à charge pour l'appareil de l'appliquer.
 */
final class Order extends Model implements Replicable
{
    use BelongsToCompany;
    use Syncable;

    public const STATUS_NEW = 'new';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'client_id',
        'order_code',
        'garment_type_id',
        'design_model_id',
        'description',
        'price',
        'deposit',
        'status',
        'assigned_employee_id',
        'due_date',
        'delivered_at',
        'paid_at',
    ];

    /**
     * Décline le code jusqu'à en trouver un libre : « AB-0042 » devient
     * « AB-0042-2 », puis « AB-0042-3 ».
     *
     * Le suffixe est préféré à un renumérotage complet : le client a
     * potentiellement déjà l'étiquette imprimée en main, on garde le code
     * qu'il lit dessus reconnaissable.
     */
    public static function availableCode(string $companyId, string $wanted, ?string $exceptId = null): string
    {
        $candidate = $wanted;

        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $taken = self::query()
                ->withTrashed()
                ->where('company_id', $companyId)
                ->where('order_code', $candidate)
                ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
                ->exists();

            if (! $taken) {
                return $candidate;
            }

            $candidate = "{$wanted}-{$suffix}";
        }

        return $wanted.'-'.mb_substr((string) $exceptId ?: uniqid(), 0, 6);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<GarmentType, $this> */
    public function garmentType(): BelongsTo
    {
        return $this->belongsTo(GarmentType::class);
    }

    /** @return BelongsTo<DesignModel, $this> */
    public function designModel(): BelongsTo
    {
        return $this->belongsTo(DesignModel::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_employee_id');
    }

    /** @return HasMany<OrderNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class);
    }

    /** @return HasMany<OrderPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    /** @return HasMany<OrderMeasurement, $this> */
    public function measurements(): HasMany
    {
        return $this->hasMany(OrderMeasurement::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price' => 'float',
            'deposit' => 'float',
            'due_date' => 'date',
            'delivered_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }
}
