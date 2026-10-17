<?php

declare(strict_types=1);

use App\Models\DiagnosticBundle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('lets only a super admin browse paginated rows from a diagnostic SQLite snapshot', function (): void {
    Storage::fake('local');
    $source = tempnam(sys_get_temp_dir(), 'coudi-test-db-');
    $archive = tempnam(sys_get_temp_dir(), 'coudi-test-zip-');

    try {
        $db = new SQLite3($source);
        $db->exec('CREATE TABLE "orders history" (id INTEGER PRIMARY KEY, label TEXT)');
        for ($id = 1; $id <= 51; $id++) {
            $db->exec("INSERT INTO \"orders history\" (id, label) VALUES ({$id}, 'Commande {$id}')");
        }
        $db->close();

        $zip = new ZipArchive;
        $zip->open($archive, ZipArchive::OVERWRITE);
        $zip->addFile($source, 'coudi.db');
        $zip->close();
        Storage::disk('local')->put('diagnostics/test.zip', file_get_contents($archive));

        $user = User::factory()->create();
        $bundle = DiagnosticBundle::query()->create([
            'user_id' => $user->id,
            'db_name' => 'coudi.db',
            'file_path' => 'diagnostics/test.zip',
            'file_size' => filesize($archive),
        ]);
        $base = "/api/v1/admin/diagnostics/bundles/{$bundle->id}/database";

        $this->actingAs($user, 'sanctum')->getJson("{$base}/tables")->assertForbidden();

        $admin = User::factory()->create();
        $admin->forceFill(['role' => User::ROLE_SUPER_ADMIN])->save();
        $this->actingAs($admin, 'sanctum')
            ->getJson("{$base}/tables")
            ->assertOk()
            ->assertJsonPath('data.tables.0', 'orders history');

        $this->getJson("{$base}/rows?table=orders%20history")
            ->assertOk()
            ->assertJsonPath('data.columns.1', 'label')
            ->assertJsonPath('data.rows.0.label', 'Commande 1')
            ->assertJsonPath('data.hasMore', true)
            ->assertJsonCount(50, 'data.rows');

        $this->getJson("{$base}/rows?table=orders%20history&page=2")
            ->assertOk()
            ->assertJsonPath('data.rows.0.label', 'Commande 51')
            ->assertJsonPath('data.hasMore', false);

        $this->getJson("{$base}/rows?table=orders%20history%22%3BDROP%20TABLE%20users%3B--")
            ->assertStatus(422);
        $this->getJson("{$base}/rows?table=sqlite_master")
            ->assertStatus(422);
    } finally {
        unlink($source);
        unlink($archive);
    }
});
