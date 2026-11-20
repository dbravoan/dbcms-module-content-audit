<?php

declare(strict_types=1);

use DbCms\Core\Contracts\Services\GeneralSettingsServiceInterface;
use DbCms\Core\DTOs\Install\LegalCompanyDTO;
use DbCms\Core\Events\PrivacyConfigurationValidating;
use DbCms\Core\Models\User;
use DbCms\Modules\ContentAudit\Models\ContentAuditRevision;
use Illuminate\Validation\ValidationException;
use Tests\Support\CmsFixtures;
use Tests\Support\ContentAuditComposition;
use Tests\Support\PrivacyFixtures;

uses(ContentAuditComposition::class);

test('audit processing requires an explicit retention rule', function (): void {
    $company = PrivacyFixtures::company();
    $entry = collect(PrivacyFixtures::processingFor(['content_audit']))->firstWhere('owner', 'content_audit');
    unset($entry['retention_days']);
    $entry['manual_review'] = false;
    $company['processing'][] = $entry;

    expect(fn () => event(new PrivacyConfigurationValidating(LegalCompanyDTO::fromArray($company))))
        ->toThrow(ValidationException::class);
});

test('editorial saves explain the missing processing and succeed after it is reviewed', function (): void {
    $this->withoutVite();
    CmsFixtures::localization(['es'], ['es']);
    CmsFixtures::identities();
    app(GeneralSettingsServiceInterface::class)->set('legal.processing', [], 'legal', 'json');
    $admin = User::where('email', 'admin@db-cms.io')->firstOrFail();
    $page = ['translations' => ['es' => ['title' => 'Página de prueba', 'slug' => 'pagina-de-prueba']], 'is_published' => 1];

    $this->actingAs($admin, 'web')->get(route('admin.modules.index'))
        ->assertOk()->assertSee(__('content_audit::privacy.processing_required'))
        ->assertSee(route('admin.settings.privacy', ['owner' => 'content_audit']));

    $this->actingAs($admin, 'web')->from(route('admin.pages.index'))->post(route('admin.pages.store'), $page)
        ->assertSessionHasErrors(['privacy' => __('content_audit::privacy.processing_required')]);
    // Privacy config is reached from modules index / site settings, not via dead layout vars.
    // A browser returns the session cookie when following the validation redirect.
    $this->withCookie((string) config('session.cookie'), session()->getId())
        ->get(route('admin.pages.index'))->assertSee(__('content_audit::privacy.processing_required'));
    $this->assertDatabaseMissing('page_translations', ['slug' => 'pagina-de-prueba']);
    expect(ContentAuditRevision::count())->toBe(0);

    app(GeneralSettingsServiceInterface::class)->set('legal.processing', PrivacyFixtures::processingFor(['content_audit']), 'legal', 'json');
    $this->get(route('admin.modules.index'))->assertDontSee(__('content_audit::privacy.processing_required'));
    $this->actingAs($admin, 'web')->post(route('admin.pages.store'), $page)
        ->assertRedirect(route('admin.pages.index'))->assertSessionHasNoErrors();
    $this->assertDatabaseHas('page_translations', ['slug' => 'pagina-de-prueba']);
    expect(ContentAuditRevision::count())->toBe(1);
});
