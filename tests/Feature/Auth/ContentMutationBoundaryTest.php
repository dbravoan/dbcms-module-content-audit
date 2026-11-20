<?php

declare(strict_types=1);

use DbCms\Core\Capabilities\AvailableCapabilities;
use DbCms\Core\Capabilities\CapabilityRegistry;
use DbCms\Core\Content\Pages\Models\Page;
use DbCms\Core\Models\Permission;
use DbCms\Core\Models\Role;
use DbCms\Core\Models\SystemNotification;
use DbCms\Core\Models\User;
use DbCms\Core\Sections\SectionEditorService;
use DbCms\Core\Services\Capabilities\ActorContextFactory;
use DbCms\Core\Services\Capabilities\EntityMutationService;
use DbCms\Core\Services\Capabilities\EntityReadService;
use DbCms\Modules\Blog\Models\Post;
use DbCms\Modules\ContentAudit\Services\AuditEntityPresentation;
use DbCms\Modules\ContentAudit\Services\AuditRevisionService;
use DbCms\Modules\ContentAudit\Services\EditorialWorkflow;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\McpComposition;

uses(McpComposition::class);

beforeEach(function (): void {
    $this->other = User::factory()->create();
    $this->actor = User::factory()->create();
    $admin = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $this->actor->roles()->attach($admin->getKey());
    $this->actingAs($this->actor);
});

test('generic create prevents forged authors', function (): void {
    $crud = app(EntityMutationService::class);
    $post = $crud->create('post', ['attributes' => ['user_id' => $this->other->getKey()], 'translations' => ['es' => ['title' => 'Real author', 'content' => 'Body']]], $this->actor);
    expect(Post::findOrFail($post['id'])->user_id)->toBe($this->actor->getKey());
    expect(Post::findOrFail($post['id'])->translations->first()->content)->toBe('Body');

});

test('entity section actions do not derive content access from general panel access', function (): void {
    $visitor = User::factory()->create();
    $role = Role::create(['slug' => 'panel-only', 'name' => 'Panel only']);
    $permission = Permission::firstOrCreate(['slug' => 'access:admin'], ['name' => 'Panel access']);
    $role->permissions()->attach($permission->getKey());
    $visitor->roles()->attach($role->getKey());
    mcpTestBindIdentity($visitor);
    $actor = app(ActorContextFactory::class)->fromRequest(request(), 'mcp');
    $available = app(AvailableCapabilities::class);
    $registry = app(CapabilityRegistry::class);
    expect($registry->get('cms_content_sections_get')->permissionFor(['type' => 'post']))->toBe('manage:posts');
    expect($registry->get('cms_content_sections_sync')->permissionFor(['type' => 'page']))->toBe('manage:pages');
    expect($registry->get('cms_audit_approve')->permissionFor(['type' => 'post']))->toBe('manage:posts');
    expect($available->reason('cms_content_sections_get', $actor, ['type' => 'post', 'id' => 1]))->not->toBeNull();
    expect($available->reason('cms_content_sections_sync', $actor, ['type' => 'page', 'id' => 1, 'sections' => []]))->not->toBeNull();
    expect($available->reason('cms_audit_approve', $actor, ['type' => 'post', 'id' => 1]))->not->toBeNull();
});

test('editing a published post stages a private revision until editorial approval', function (): void {
    $crud = app(EntityMutationService::class);
    $payload = $crud->create('post', ['attributes' => ['publish' => true], 'translations' => ['es' => ['title' => 'Live post', 'content' => 'Live body']]], $this->actor);
    $updated = $crud->update('post', $payload['id'], ['translations' => ['es' => ['content' => 'Pending body']]]);
    $post = Post::findOrFail($payload['id']);
    expect($post->is_published)->toBeTrue();
    expect($post->translations->first()->content)->toBe('Live body');
    expect($updated['status'])->toBe('draft');
    $audit = app(EditorialWorkflow::class);
    $audit->approve($post, $this->actor);
    expect($post->fresh()->translations->first()->content)->toBe('Pending body');
    expect($post->fresh()->is_published)->toBeTrue();
    expect(SystemNotification::query()->where('module', 'content_audit')->where('type', 'approval')->firstOrFail()->user_id)->toBe($this->actor->getKey());
});

test('editing published page blocks preserves the live version until editorial approval', function (): void {
    $crud = app(EntityMutationService::class);
    $payload = $crud->create('page', ['attributes' => ['publish' => true, 'layout' => 'app'], 'translations' => ['es' => ['title' => 'Live page', 'slug' => 'live-page']], 'sections_json' => json_encode(['es' => [['type' => 'text', 'content' => 'Live block']]])], $this->actor);
    $before = app(SectionEditorService::class)->payload(Page::findOrFail($payload['id']));
    $crud->update('page', $payload['id'], ['attributes' => ['layout' => 'new-layout'], 'sections_json' => json_encode(['es' => [['type' => 'text', 'content' => 'Pending block']]])]);
    $page = Page::findOrFail($payload['id']);
    expect($page->is_published)->toBeTrue();
    expect($page->layout)->toBe('app');
    expect(app(SectionEditorService::class)->payload($page))->toBe($before);
    app(EditorialWorkflow::class)->approve($page, $this->actor);
    expect($page->fresh()->layout)->toBe('new-layout');
    expect(json_encode(app(SectionEditorService::class)->payload($page->fresh())))->toContain('Pending block');
});

test('successive drafts merge changes and stale approvals preserve newer live content', function (): void {
    $crud = app(EntityMutationService::class);
    $payload = $crud->create('post', ['publish' => true, 'translations' => ['es' => ['title' => 'Live', 'content' => 'Original']]], $this->actor);
    $crud->update('post', $payload['id'], ['translations' => ['es' => ['content' => 'Draft body']]]);
    $crud->update('post', $payload['id'], ['translations' => ['es' => ['title' => 'Draft title']]]);
    $post = Post::findOrFail($payload['id']);
    expect($post->translations->first()->title)->toBe('Live');
    app(EditorialWorkflow::class)->approve($post, $this->actor);
    expect($post->fresh()->translations->first()->title)->toBe('Draft title');
    expect($post->fresh()->translations->first()->content)->toBe('Draft body');
    $crud->update('post', $payload['id'], ['translations' => ['es' => ['content' => 'Second draft']]]);
    $post->translations()->first()->forceFill(['content' => 'Newer live body'])->saveQuietly();
    try {
        app(EditorialWorkflow::class)->approve($post->fresh(), $this->actor);
        $this->fail('A stale draft must not overwrite newer live content.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(409);
    }
    expect($post->fresh()->translations->first()->content)->toBe('Newer live body');
});

test('approving an explicit deactivation preserves the requested visibility', function (string $type, string $flag, array $translations): void {
    $crud = app(EntityMutationService::class);
    $payload = $crud->create($type, ['publish' => true, 'translations' => ['es' => $translations]], $this->actor);
    $entity = app(EntityReadService::class)->resolveEntity($type, $payload['id']);
    expect((bool) $entity->getAttribute($flag))->toBeTrue();
    $crud->update($type, $payload['id'], ['attributes' => [$flag => false]]);
    expect((bool) $entity->fresh()->getAttribute($flag))->toBeTrue();
    // A later content-only save must retain the pending deactivation intent.
    $crud->update($type, $payload['id'], ['translations' => ['es' => $translations]]);
    $entity = $entity->fresh();
    $audit = app(AuditRevisionService::class)->auditFor($entity);
    expect($audit->revisions()->whereKey($audit->pending_revision_id)->firstOrFail()->snapshot['_visibility_intent']['value'])->toBeFalse();
    $this->actingAs($this->actor);
    $slug = app(AuditEntityPresentation::class)->slug($entity);
    $review = $this->get(route('admin.audit.review', ['type' => $slug, 'id' => $entity->getKey()]))->assertOk();
    expect((bool) $entity->fresh()->getAttribute($flag))->toBeTrue();
    $this->post(route('admin.audit.approve', ['type' => $slug, 'id' => $entity->getKey()]), ['review_token' => $review->viewData('reviewToken')])->assertRedirect(route('admin.audit.index'));
    expect((bool) $entity->fresh()->getAttribute($flag))->toBeFalse();
    expect(app(AuditRevisionService::class)->auditFor($entity)->status)->toBe('approved');
})->with([
    'page' => ['page', 'is_published', ['title' => 'Live page pending deactivation', 'content' => 'Body']],
]);

test('rejecting a stale draft archives it and allows a new draft without reverting live content', function (): void {
    $page = Model::withoutEvents(fn () => Page::create(['template' => 'default', 'layout' => 'app', 'is_published' => true]));
    $page->translations()->create(['locale' => 'es', 'title' => 'Live page', 'slug' => 'live-page']);
    $crud = app(EntityMutationService::class);
    $audits = app(EditorialWorkflow::class);
    $crud->update('page', $page->getKey(), ['attributes' => ['layout' => 'pending-layout']]);
    Model::withoutEvents(fn () => $page->refresh()->update(['layout' => 'concurrent-layout']));
    try {
        $audits->approve($page->fresh(), $this->actor);
        $this->fail('A concurrent live change must invalidate approval.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(409);
    }
    $decision = $audits->reject($page->fresh(), $this->actor, 'Descartar la revisión anterior');
    expect($decision->revisionId)->toBeNull();
    $audit = app(AuditRevisionService::class)->auditFor($page);
    expect($audit->pending_revision_id)->toBeNull();
    $rejected = $audit->revisions()->where('action', 'rejected')->latest('version')->firstOrFail();
    expect($rejected->snapshot['attributes']['layout'])->toBe('pending-layout');
    expect($page->fresh()->layout)->toBe('concurrent-layout')->and($page->fresh()->is_published)->toBeTrue();
    $next = $crud->update('page', $page->getKey(), ['attributes' => ['layout' => 'next-layout']]);
    expect($next['live_preserved'])->toBeTrue();
    $audits->approve($page->fresh(), $this->actor);
    expect($page->fresh()->layout)->toBe('next-layout')->and($page->fresh()->is_published)->toBeTrue();
});
