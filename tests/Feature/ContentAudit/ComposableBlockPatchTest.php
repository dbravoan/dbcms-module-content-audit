<?php

declare(strict_types=1);

use DbCms\Core\Contracts\Services\ModuleSettingsServiceInterface;
use DbCms\Core\Models\Market;
use DbCms\Core\Models\User;
use DbCms\Core\Services\Capabilities\ContentSectionActions;
use DbCms\Core\Services\Capabilities\EntityMutationService;
use DbCms\Modules\Blog\Models\Post;
use DbCms\Modules\ContentAudit\Services\EditorialWorkflow;
use Tests\Support\CmsFixtures;
use Tests\Support\McpEditorialComposition;

uses(McpEditorialComposition::class);

it('patches nested pending nodes through optional review without touching the live version', function (): void {
    CmsFixtures::localization();
    CmsFixtures::identities();
    $actor = User::query()->where('email', 'admin@db-cms.io')->firstOrFail();
    $this->actingAs($actor);
    app(ModuleSettingsServiceInterface::class)->set('content_audit', 'is_enabled', true);
    app(ModuleSettingsServiceInterface::class)->set('content_audit', 'notify_in_app', false);
    $market = Market::query()->where('code', 'es')->firstOrFail();
    $created = app(EntityMutationService::class)->create('post', [
        'publish' => true, 'market_ids' => [$market->id],
        'translations' => ['es' => ['title' => 'Artículo público', 'content' => 'Texto publicado']],
    ], $actor);
    $post = Post::query()->findOrFail($created['id']);
    $sections = app(ContentSectionActions::class);
    $draft = $sections->sectionsSync(['type' => 'post', 'id' => $post->id, 'sections' => [
        'es' => [['type' => 'accordion', 'items' => [['title' => 'Panel', 'children' => [['id' => 'draft-title', 'type' => 'heading', 'text' => 'Primer borrador']]]]]],
    ]]);
    $patched = $sections->sectionsPatch(['type' => 'post', 'id' => $post->id, 'locale' => 'es', 'version' => $draft['version'], 'operations' => [
        ['op' => 'update', 'node_id' => 'draft-title', 'values' => ['text' => 'Borrador revisado']],
    ]]);
    expect($patched['live_preserved'])->toBeTrue()
        ->and($patched['pending_revision_id'])->toBeInt()
        ->and($post->sections()->count())->toBe(0)
        ->and($post->fresh()->translations->first()->content)->toBe('Texto publicado')
        ->and($sections->sectionsGet(['type' => 'post', 'id' => $post->id, 'node_id' => 'draft-title'])['nodes']['es'][0]['text'])->toBe('Borrador revisado');
    app(EditorialWorkflow::class)->approve($post, $actor);
    expect($post->fresh()->publishedSections()->count())->toBe(1)
        ->and(json_encode($sections->sectionsGet(['type' => 'post', 'id' => $post->id])))->toContain('Borrador revisado');
});
