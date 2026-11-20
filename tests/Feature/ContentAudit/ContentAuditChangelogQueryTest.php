<?php

declare(strict_types=1);

use DbCms\Core\Contracts\Services\ModuleSettingsServiceInterface;
use DbCms\Core\Models\Language;
use DbCms\Core\Models\Market;
use DbCms\Core\Models\Permission;
use DbCms\Core\Models\Role;
use DbCms\Core\Models\User;
use DbCms\Core\Modules\ModuleManager;
use DbCms\Modules\Blog\Models\Post;
use DbCms\Modules\ContentAudit\Enums\AuditAction;
use DbCms\Modules\ContentAudit\Models\ContentAuditRevision;
use DbCms\Modules\ContentAudit\Services\AuditWorkbenchService;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BlogAuditComposition;

uses(BlogAuditComposition::class);

beforeEach(function (): void {
    Language::firstOrCreate(['code' => 'es'], ['name' => 'Spanish', 'native_name' => 'Español', 'is_default' => true, 'is_active' => true]);
    Market::firstOrCreate(['code' => 'es'], ['name' => 'España', 'default_locale' => 'es', 'currency' => 'EUR', 'is_default' => true, 'is_active' => true]);

    $access = Permission::firstOrCreate(['slug' => 'access:admin'], ['name' => 'Administrative access']);
    $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
    $adminRole->permissions()->syncWithoutDetaching([$access->id]);

    $this->admin = User::factory()->create();
    $this->admin->roles()->attach($adminRole);

    app(ModuleManager::class)->discoverModules();
    $settings = app(ModuleSettingsServiceInterface::class);
    $settings->set('content_audit', 'is_enabled', true);
});

test('the global changelog paginates without loading snapshots or sorting them in memory', function (): void {
    $payload = ['body' => str_repeat('x', 400_000)];

    foreach (range(1, 5) as $version) {
        ContentAuditRevision::create([
            'auditable_type' => (new Post)->getMorphClass(),
            'auditable_id' => $version,
            'user_id' => $this->admin->id,
            'version' => $version,
            'action' => AuditAction::UPDATED->value,
            'title' => 'Revision '.$version,
            'snapshot' => $payload,
            'diff' => $payload,
        ]);
    }

    $result = app(AuditWorkbenchService::class)->index($this->admin, ['tab' => 'changelog']);
    $revisions = $result['revisions'];

    expect($revisions->total())->toBe(5)
        ->and($revisions->items())->toHaveCount(5)
        ->and($revisions->items()[0]->getAttributes())->not->toHaveKey('snapshot')
        ->and($revisions->items()[0]->getAttributes())->not->toHaveKey('diff')
        ->and($revisions->items()[0]->title)->toStartWith('Revision');

    $this->actingAs($this->admin)
        ->get(route('admin.audit.index', ['tab' => 'changelog']))
        ->assertOk()
        ->assertSee('Revision 1');

    $indexes = collect(Schema::getIndexes('content_audit_revisions'));
    expect($indexes->contains(fn (array $index): bool => in_array('created_at', $index['columns'], true)))->toBeTrue();
});

test('the review timeline still exposes revision metadata while the diff keeps its previous snapshot', function (): void {
    $payload = ['body' => str_repeat('y', 400_000)];

    foreach (range(1, 3) as $version) {
        ContentAuditRevision::create([
            'auditable_type' => (new Post)->getMorphClass(),
            'auditable_id' => 99,
            'user_id' => $this->admin->id,
            'version' => $version,
            'action' => AuditAction::UPDATED->value,
            'title' => 'Revision '.$version,
            'snapshot' => $payload,
            'diff' => $payload,
        ]);
    }

    $query = ContentAuditRevision::query()
        ->where('auditable_type', (new Post)->getMorphClass())
        ->where('auditable_id', 99)
        ->orderByDesc('version');
    $list = (clone $query)->with('user')->get(ContentAuditRevision::LIST_COLUMNS);
    $previous = (clone $query)->skip(1)->first();

    expect($list)->toHaveCount(3)
        ->and($list->first()->getAttributes())->not->toHaveKey('snapshot')
        ->and($previous)->not->toBeNull()
        ->and($previous->version)->toBe(2)
        ->and($previous->snapshot)->toBe($payload);
});
