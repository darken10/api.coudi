<?php

declare(strict_types=1);

namespace App\Sync;

use App\Models\Client;
use App\Models\ClientMeasurement;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\DesignModel;
use App\Models\Employee;
use App\Models\EmployeePieceRate;
use App\Models\GarmentType;
use App\Models\GarmentTypeField;
use App\Models\MeasurementField;
use App\Models\MediaAsset;
use App\Models\ModelGallery;
use App\Models\Order;
use App\Models\OrderMeasurement;
use App\Models\OrderNote;
use App\Models\OrderPayment;
use App\Models\SalaryPayment;
use App\Models\WorkLog;
use InvalidArgumentException;

/**
 * Catalogue des entités répliquées.
 *
 * L'ordre de déclaration est l'ordre d'application d'un push : un parent est
 * toujours traité avant ses enfants, sans quoi un lot créé hors ligne
 * (un client, sa commande, son acompte) échouerait sur la moitié de ses lignes
 * au premier envoi.
 */
final class SyncRegistry
{
    /** @var array<string, SyncEntity>|null */
    private static ?array $entities = null;

    /** @return array<string, SyncEntity> */
    public static function all(): array
    {
        return self::$entities ??= self::build();
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    public static function get(string $key): SyncEntity
    {
        return self::all()[$key] ?? throw new InvalidArgumentException("Entité inconnue : {$key}");
    }

    /**
     * Trie des clés dans l'ordre de dépendance du registre.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function ordered(array $keys): array
    {
        return array_values(array_filter(self::keys(), static fn (string $k): bool => in_array($k, $keys, true)));
    }

    /** @return array<string, SyncEntity> */
    private static function build(): array
    {
        $entities = [
            new SyncEntity(
                key: 'companies',
                model: Company::class,
                strategy: ConflictStrategy::LastWriteWins,
                fields: ['name', 'description', 'owner_name', 'address', 'phone', 'email',
                    'website', 'logo_uri', 'status', 'archived_at'],
            ),
            new SyncEntity(
                key: 'company_settings',
                model: CompanySetting::class,
                strategy: ConflictStrategy::LastWriteWins,
                fields: ['currency', 'measure_unit', 'default_deadline_days', 'order_prefix',
                    'label_format', 'label_show_price', 'label_show_tailor', 'label_show_phone'],
                naturalKey: ['company_id'],
            ),
            new SyncEntity(
                key: 'measurement_fields',
                model: MeasurementField::class,
                strategy: ConflictStrategy::LastWriteWins,
                fields: ['name', 'category', 'sort_order'],
            ),
            new SyncEntity(
                key: 'garment_types',
                model: GarmentType::class,
                strategy: ConflictStrategy::LastWriteWins,
                fields: ['name', 'complexity', 'base_price', 'is_active'],
            ),
            new SyncEntity(
                key: 'garment_type_fields',
                model: GarmentTypeField::class,
                strategy: ConflictStrategy::NaturalKeyUpsert,
                fields: ['garment_type_id', 'field_id', 'sort_order'],
                parents: ['garment_type_id' => 'garment_types', 'field_id' => 'measurement_fields'],
                naturalKey: ['garment_type_id', 'field_id'],
                companyFrom: 'garment_type_id',
            ),
            new SyncEntity(
                key: 'employees',
                model: Employee::class,
                strategy: ConflictStrategy::LastWriteWins,
                fields: ['name', 'role', 'phone', 'email', 'salary', 'status', 'hired_at',
                    'payment_type', 'rate_amount'],
            ),
            new SyncEntity(
                key: 'employee_piece_rates',
                model: EmployeePieceRate::class,
                strategy: ConflictStrategy::NaturalKeyUpsert,
                fields: ['employee_id', 'garment_type_id', 'rate'],
                parents: ['employee_id' => 'employees', 'garment_type_id' => 'garment_types'],
                naturalKey: ['employee_id', 'garment_type_id'],
                companyFrom: 'employee_id',
            ),
            new SyncEntity(
                key: 'clients',
                model: Client::class,
                strategy: ConflictStrategy::LastWriteWins,
                fields: ['name', 'phone', 'email', 'address', 'notes', 'photo_uri',
                    'birth_date', 'event_date', 'event_label', 'is_vip', 'is_active'],
            ),
            new SyncEntity(
                key: 'client_measurements',
                model: ClientMeasurement::class,
                strategy: ConflictStrategy::NaturalKeyUpsert,
                fields: ['client_id', 'garment_type_id', 'field_id', 'value', 'measured_at'],
                parents: [
                    'client_id' => 'clients',
                    'garment_type_id' => 'garment_types',
                    'field_id' => 'measurement_fields',
                ],
                naturalKey: ['client_id', 'garment_type_id', 'field_id'],
                companyFrom: 'client_id',
            ),
            new SyncEntity(
                key: 'model_galleries',
                model: ModelGallery::class,
                strategy: ConflictStrategy::LastWriteWins,
                fields: ['name', 'description', 'cover_uri', 'sort_order', 'is_active'],
            ),
            new SyncEntity(
                key: 'design_models',
                model: DesignModel::class,
                strategy: ConflictStrategy::LastWriteWins,
                fields: ['gallery_id', 'garment_type_id', 'name', 'reference', 'description',
                    'base_price', 'cover_uri', 'tags', 'is_active', 'usage_count'],
                parents: ['gallery_id' => 'model_galleries', 'garment_type_id' => 'garment_types'],
            ),
            new SyncEntity(
                key: 'orders',
                model: Order::class,
                strategy: ConflictStrategy::LastWriteWins,
                fields: ['client_id', 'order_code', 'garment_type_id', 'design_model_id',
                    'description', 'price', 'deposit', 'status', 'assigned_employee_id',
                    'due_date', 'delivered_at', 'paid_at'],
                parents: [
                    'client_id' => 'clients',
                    'garment_type_id' => 'garment_types',
                    'design_model_id' => 'design_models',
                    'assigned_employee_id' => 'employees',
                ],
                companyFrom: 'client_id',
            ),
            new SyncEntity(
                key: 'order_notes',
                model: OrderNote::class,
                strategy: ConflictStrategy::AppendOnly,
                fields: ['order_id', 'content'],
                parents: ['order_id' => 'orders'],
                companyFrom: 'order_id',
            ),
            new SyncEntity(
                key: 'order_payments',
                model: OrderPayment::class,
                strategy: ConflictStrategy::AppendOnly,
                fields: ['order_id', 'amount', 'note'],
                parents: ['order_id' => 'orders'],
                companyFrom: 'order_id',
            ),
            new SyncEntity(
                key: 'order_measurements',
                model: OrderMeasurement::class,
                strategy: ConflictStrategy::NaturalKeyUpsert,
                fields: ['order_id', 'field_id', 'value'],
                parents: ['order_id' => 'orders', 'field_id' => 'measurement_fields'],
                naturalKey: ['order_id', 'field_id'],
                companyFrom: 'order_id',
            ),
            new SyncEntity(
                key: 'work_logs',
                model: WorkLog::class,
                strategy: ConflictStrategy::AppendOnly,
                fields: ['employee_id', 'garment_type_id', 'quantity', 'work_date', 'notes'],
                parents: ['employee_id' => 'employees', 'garment_type_id' => 'garment_types'],
                companyFrom: 'employee_id',
            ),
            new SyncEntity(
                key: 'salary_payments',
                model: SalaryPayment::class,
                strategy: ConflictStrategy::AppendOnly,
                fields: ['employee_id', 'amount', 'period_start', 'period_end', 'paid_at', 'notes'],
                parents: ['employee_id' => 'employees'],
                companyFrom: 'employee_id',
            ),
            new SyncEntity(
                key: 'media_assets',
                model: MediaAsset::class,
                strategy: ConflictStrategy::LastWriteWins,
                fields: ['owner_type', 'owner_id', 'category', 'type', 'uri', 'file_name',
                    'mime_type', 'size_bytes', 'width', 'height', 'duration_ms', 'caption',
                    'sort_order'],
            ),
        ];

        $keyed = [];

        foreach ($entities as $entity) {
            $keyed[$entity->key] = $entity;
        }

        return $keyed;
    }
}
