<?php

declare(strict_types=1);

use DbCms\Core\Models\BlockComponent;
use DbCms\Core\Models\User;
use DbCms\Modules\Popups\Models\Popup;
use Tests\Support\CmsFixtures;
use Tests\Support\PopupsAuditComposition;

uses(PopupsAuditComposition::class);

beforeEach(function (): void {
    $this->withoutVite();
    CmsFixtures::localization();
    CmsFixtures::identities(['admin' => [], 'viewer' => ['access:admin']]);
    $this->actingAs(User::where('email', 'admin@db-cms.io')->sole());
});

dataset('unified editorial listings', [
    'templates' => [BlockComponent::class, 'admin.block-components.index', 'components'],
    'popups' => [Popup::class, 'admin.popups.index', 'popups'],
]);

it('searches translated titles and combines publication and availability filters', function (string $model, string $route, string $variable): void {
    foreach ([[true, false, 'Match'], [false, false, 'Inactive'], [true, true, 'Published']] as [$active, $published, $name]) {
        $record = $model::create(['is_active' => $active, 'is_published' => $published] + ($model === Popup::class ? ['configuration' => []] : []));
        $record->translations()->create(['locale' => 'es', 'title' => 'Plantilla '.$name, 'slug' => 'es-'.$name]);
        $record->translations()->create(['locale' => 'en', 'title' => 'English '.$name, 'slug' => 'en-'.$name]);
    }
    $this->get(route($route, ['search' => 'English', 'is_active' => '1', 'is_published' => '0']))
        ->assertOk()->assertSee('Plantilla Match')->assertDontSee('Plantilla Inactive')->assertDontSee('Plantilla Published')
        ->assertViewHas($variable, fn ($rows): bool => $rows->total() === 1 && $rows->first()->getAttribute('admin_status') === 'draft');
})->with('unified editorial listings');

it('paginates while retaining the active query', function (string $model, string $route, string $variable): void {
    for ($index = 0; $index < 12; $index++) {
        $record = $model::create(['is_active' => true, 'is_published' => false] + ($model === Popup::class ? ['configuration' => []] : []));
        $record->translations()->create(['locale' => 'es', 'title' => 'Resultado '.$index, 'slug' => 'resultado-'.$index]);
    }
    $this->get(route($route, ['search' => 'Resultado', 'is_published' => '0', 'per_page' => 10]))
        ->assertOk()->assertViewHas($variable, fn ($rows): bool => $rows->total() === 12 && $rows->count() === 10 && str_contains($rows->url(2), 'search=Resultado') && str_contains($rows->url(2), 'is_published=0'));
})->with('unified editorial listings');

it('rejects malformed filters and unauthorized users', function (string $model, string $route, string $variable): void {
    $this->getJson(route($route, ['is_active' => ['1']]))->assertUnprocessable();
    $this->actingAs(User::where('email', 'viewer@db-cms.io')->sole());
    $this->get(route($route))->assertForbidden();
})->with('unified editorial listings');
