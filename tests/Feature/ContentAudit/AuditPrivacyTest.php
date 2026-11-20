<?php

declare(strict_types=1);

use DbCms\Core\Content\Pages\Models\Page;
use DbCms\Core\Contracts\Services\GeneralSettingsServiceInterface;
use DbCms\Core\Models\User;
use DbCms\Core\Modules\ModuleManager;
use DbCms\Core\Services\Capabilities\ContentSnapshotService;
use DbCms\Modules\ContentAudit\Models\ContentAudit;
use DbCms\Modules\ContentAudit\Models\ContentAuditRevision;
use DbCms\Modules\ContentAudit\Services\AuditPrivacyService;
use DbCms\Modules\ContentAudit\Services\AuditRevisionService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\ContentAuditComposition;
use Tests\Support\PrivacyFixtures;

uses(ContentAuditComposition::class);

beforeEach(function (): void {
    $this->page = Page::create(['template' => 'default', 'is_published' => false]);
    $this->actor = User::factory()->create(['name' => 'Named editor']);
    $this->history = ContentAudit::create(['auditable_type' => $this->page->getMorphClass(), 'auditable_id' => $this->page->id, 'status' => 'published', 'current_version' => 4, 'notes' => 'Reviewed personal editorial note']);
    $snapshot = app(ContentSnapshotService::class)->capture($this->page);
    $this->revisions = collect(range(1, 4))->map(fn (int $version): ContentAuditRevision => ContentAuditRevision::forceCreate([
        'content_audit_id' => $this->history->id, 'auditable_type' => $this->page->getMorphClass(), 'auditable_id' => $this->page->id,
        'version' => $version, 'user_id' => $this->actor->id, 'action' => 'updated', 'title' => 'Private revision '.$version,
        'summary' => 'Named editor edited this page', 'snapshot' => $snapshot, 'diff' => ['before' => 'Retained comparison'],
        'notes' => 'Private review', 'created_at' => now()->subDays(60),
    ]));
});

test('new revisions require reviewed processing and do not copy network metadata', function (): void {
    app(GeneralSettingsServiceInterface::class)->set('legal.processing', [], 'legal', 'json');
    expect(fn () => app(AuditRevisionService::class)->record($this->page, $this->actor, 'updated'))->toThrow(ValidationException::class);
    expect(ContentAuditRevision::where('auditable_id', $this->page->id)->max('version'))->toBe(4);
    app(GeneralSettingsServiceInterface::class)->set('legal.processing', PrivacyFixtures::processingFor(['content_audit']), 'legal', 'json');
    request()->server->set('REMOTE_ADDR', '203.0.113.12');
    request()->headers->set('User-Agent', 'Private browser identifier');
    $revision = app(AuditRevisionService::class)->record($this->page, $this->actor, 'updated');
    expect($revision->ip_address)->toBeNull()->and($revision->user_agent)->toBeNull()
        ->and($revision->user_id)->toBe($this->actor->id)->and($revision->snapshot)->not->toBeEmpty();
});

test('retention preserves pending review then only removes superseded full records', function (): void {
    $this->history->update(['pending_revision_id' => $this->revisions[0]->id]);
    $before = $this->revisions[3]->fresh()->getRawOriginal('snapshot');
    $privacy = app(AuditPrivacyService::class);
    expect($privacy->prune())->toBe(0);
    $this->history->update(['pending_revision_id' => null]);
    expect($privacy->prune())->toBe(2)
        ->and(ContentAuditRevision::orderBy('version')->pluck('version')->all())->toBe([3, 4])
        ->and($this->revisions[3]->fresh()->getRawOriginal('snapshot'))->toBe($before)
        ->and($this->history->fresh()->notes)->toBe('Reviewed personal editorial note');
});

test('a mixed erasure request rolls back rather than breaking the current restore chain', function (): void {
    expect(fn () => app(AuditPrivacyService::class)->eraseRevisions([$this->revisions[0]->id, $this->revisions[3]->id]))->toThrow(DomainException::class);
    expect($this->revisions[0]->fresh())->not->toBeNull()->and($this->revisions[3]->fresh())->not->toBeNull();
});

test('whole history release requires deleted content explicit review and elapsed retention', function (): void {
    $privacy = app(AuditPrivacyService::class);
    expect(fn () => $privacy->eraseHistories([$this->history->id]))->toThrow(DomainException::class)
        ->and(fn () => $privacy->eraseHistories([$this->history->id], true))->toThrow(DomainException::class);
    $this->page->deleteQuietly();
    $this->history->forceFill(['status' => 'deleted', 'updated_at' => now()->subDays(60)])->save();
    expect($privacy->eraseHistories([$this->history->id], true))->toBe(1)
        ->and($this->history->fresh())->toBeNull()
        ->and(ContentAuditRevision::whereIn('id', $this->revisions->pluck('id')->all())->exists())->toBeFalse();
});

test('inactive history exports require verified scope and remain in private storage', function (): void {
    Storage::fake('local');
    app(ModuleManager::class)->deactivate('content_audit');
    $this->artisan('content-audit:privacy', ['action' => 'export', 'kind' => 'histories', '--id' => [$this->history->id]])->assertFailed();
    expect(Storage::disk('local')->allFiles())->toBe([]);
    $this->artisan('content-audit:privacy', ['action' => 'export', 'kind' => 'histories', '--id' => [$this->history->id], '--verified' => true])->assertSuccessful();
    $files = Storage::disk('local')->allFiles('privacy');
    expect($files)->toHaveCount(1);
    $export = json_decode(Storage::disk('local')->get($files[0]), true, flags: JSON_THROW_ON_ERROR);
    expect($export[0]['id'])->toBe($this->history->id)->and($export[0]['revisions'][0]['snapshot'])->not->toBeEmpty();
});
