<?php

declare(strict_types=1);

use DbCms\Core\Capabilities\ActorContext;
use DbCms\Core\Capabilities\AvailableCapabilities;
use DbCms\Core\Capabilities\CallableCapability;
use DbCms\Core\Capabilities\CapabilityAuthorizer;
use DbCms\Core\Capabilities\CapabilityRegistry;
use DbCms\Core\Capabilities\Contracts\CapabilityPresentationInterface;
use DbCms\Core\Contracts\Services\SearchAnalyticsInterface;
use DbCms\Core\Maintenance\Capabilities\MaintenanceActionCatalog;
use DbCms\Core\Maintenance\Capabilities\MaintenanceActions;
use DbCms\Core\Modules\ModuleManager;
use DbCms\Core\Search\Capabilities\SearchActionCatalog;
use DbCms\Core\Search\Capabilities\SearchActions;
use DbCms\Core\Search\SearchIndexer;
use DbCms\Core\Services\Capabilities\CmsActionCatalog;
use DbCms\Modules\ContentAudit\Capabilities\EditorialActionCatalog;
use DbCms\Modules\ContentAudit\Capabilities\EditorialActions;

test('optional module actions do not belong to the always available core action catalog', function (): void {
    $names = array_map(static fn ($tool): string => $tool->name(), app(CmsActionCatalog::class)->all());
    foreach (['cms_maintenance_get', 'cms_maintenance_toggle', 'cms_search_reindex', 'cms_search_stats', 'cms_audit_approve', 'cms_audit_reject', 'cms_audit_rollback'] as $name) {
        expect($names)->not->toContain($name);
    }
});

test('module catalogs declare their owners and the actual effects of every action', function (): void {
    $registry = new CapabilityRegistry;
    MaintenanceActionCatalog::register($registry, app(MaintenanceActions::class));
    SearchActionCatalog::register($registry, new SearchActions(app(SearchIndexer::class), Mockery::mock(SearchAnalyticsInterface::class)));
    EditorialActionCatalog::register($registry, app(EditorialActions::class));
    foreach ([
        'cms_maintenance_get' => [null, 'read'],
        'cms_maintenance_toggle' => [null, 'external'],
        'cms_search_stats' => [null, 'read'],
        'cms_search_reindex' => [null, 'draft'],
        'cms_audit_approve' => ['content_audit', 'publish'],
        'cms_audit_rollback' => ['content_audit', 'publish'],
        'cms_audit_reject' => ['content_audit', 'publish'],
    ] as $name => [$owner, $effect]) {
        expect($registry->metadata($name)['module'])->toBe($owner);
        expect($registry->metadata($name)['effect'])->toBe($effect);
    }
    expect($registry->get('cms_maintenance_get')->handle([])['active'])->toBeFalse();
});

test('disabling owners removes their actions from discovery and refuses direct execution availability', function (): void {
    $registry = new CapabilityRegistry;
    MaintenanceActionCatalog::register($registry, app(MaintenanceActions::class));
    SearchActionCatalog::register($registry, new SearchActions(app(SearchIndexer::class), Mockery::mock(SearchAnalyticsInterface::class)));
    EditorialActionCatalog::register($registry, app(EditorialActions::class));
    $enabled = false;
    $modules = Mockery::mock(ModuleManager::class);
    $modules->shouldReceive('isModuleEnabled')->andReturnUsing(function () use (&$enabled): bool {
        return $enabled;
    });
    $authorizer = Mockery::mock(CapabilityAuthorizer::class);
    $authorizer->shouldReceive('allowed')->andReturnTrue();
    $presentation = Mockery::mock(CapabilityPresentationInterface::class);
    $presentation->shouldReceive('reason')->andReturnNull();
    $presentation->shouldReceive('schema')->andReturnUsing(static fn (string $name, array $entry): array => $entry);
    $available = new AvailableCapabilities($registry, $authorizer, $modules, $presentation);
    $actor = new ActorContext(1);
    expect($available->manifest($actor))->toHaveCount(4);
    foreach ($registry->names() as $name) {
        expect($available->reason($name, $actor))->toBe($registry->metadata($name)['module'] === null ? null : 'Módulo no disponible.');
    }
    $enabled = true;
    expect($available->manifest($actor))->toHaveCount(7);
    $registry->register(new CallableCapability('dependency_probe', 'Dependency probe', [], [], null, 'read', static fn (): array => []), dependencies: ['model:text']);
    $manifest = array_column($available->manifest($actor), null, 'name');
    expect($manifest['dependency_probe']['dependencies'])->toBe(['model:text']);
});
