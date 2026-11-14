<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Device;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{Company, Device, array<string, string>} */
function stockWorkshop(): array
{
    $user = User::factory()->create();

    $company = new Company;
    $company->fill(['name' => 'Atelier Stock', 'status' => Company::STATUS_ACTIVE]);
    $company->save();

    $company->members()->attach($user->id, ['role' => Company::ROLE_OWNER, 'joined_at' => now()]);

    $device = Device::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'platform' => 'android',
    ]);

    Sanctum::actingAs($user);

    return [$company, $device, [
        'X-Company-Id' => $company->getKey(),
        'X-Device-Id' => $device->getKey(),
    ]];
}

describe('Stock', function (): void {
    it('livre les matières et leurs mouvements via la synchronisation', function (): void {
        [$company, $device, $headers] = stockWorkshop();

        $item = StockItem::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Pagne wax',
            'unit' => 'm',
            'low_stock_threshold' => 10,
        ]);

        StockMovement::query()->create([
            'company_id' => $company->getKey(),
            'stock_item_id' => $item->getKey(),
            'type' => StockMovement::TYPE_IN,
            'quantity' => 40,
        ]);

        StockMovement::query()->create([
            'company_id' => $company->getKey(),
            'stock_item_id' => $item->getKey(),
            'type' => StockMovement::TYPE_OUT,
            'quantity' => 5,
        ]);

        $changes = $this->postJson('/api/v1/sync/pull', ['since' => 0], $headers)
            ->assertOk()
            ->json('data.changes');

        expect(collect($changes['stock_items'] ?? [])->pluck('id'))->toContain($item->getKey())
            ->and($changes['stock_movements'] ?? [])->toHaveCount(2);
    });

    it('rattache un mouvement créé hors ligne à l’atelier de sa matière', function (): void {
        [$company, , $headers] = stockWorkshop();

        $item = StockItem::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Fil',
            'unit' => 'piece',
        ]);

        $movementId = (string) Str::uuid();

        $this->postJson('/api/v1/sync/push', ['ops' => [[
            'op_id' => (string) Str::uuid(),
            'entity' => 'stock_movements',
            'op' => 'upsert',
            'id' => $movementId,
            'updated_at' => now()->toIso8601String(),
            'data' => ['stock_item_id' => $item->getKey(), 'type' => 'out', 'quantity' => 3],
        ]]], $headers)->assertOk();

        $this->assertDatabaseHas('stock_movements', [
            'id' => $movementId,
            'company_id' => $company->getKey(),
            'type' => StockMovement::TYPE_OUT,
        ]);
    });
});
