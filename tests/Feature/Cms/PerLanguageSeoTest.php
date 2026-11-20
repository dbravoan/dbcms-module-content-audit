<?php

declare(strict_types=1);

use DbCms\Core\Content\Pages\Contracts\PageDomainServiceInterface;
use DbCms\Core\Content\Pages\Models\Page;
use DbCms\Core\Models\Language;
use DbCms\Core\Models\User;
use DbCms\Modules\ContentAudit\Services\EditorialWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CmsFixtures;
use Tests\Support\PerLanguageSeoComposition;

uses(RefreshDatabase::class, PerLanguageSeoComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization(['es', 'en', 'fr']);
    CmsFixtures::identities();
    CmsFixtures::home(['es', 'en', 'fr']);
});

/**
 * The SEO is edited **per language**, exactly like the title, the slug and the
 * sections. The market only decides where the page is shown, so a page
 * associated to a market is displayed there with the SEO of the active locale.
 *
 * Before this contract the SEO box was rendered once and only the active tab was
 * submitted, so editing English stamped the same meta on every language; products
 * and galleries ignored the box entirely and derived the meta from the name.
 */
test('a single page save persists the SEO of every language', function (): void {
    $page = Page::whereHas('translations')->firstOrFail();
    $admin = User::first();
    expect($page->is_published)->toBeTrue();
    $liveMeta = $page->translations->pluck('meta_title', 'locale')->all();

    $this->actingAs($admin, 'web')
        ->put(route('admin.pages.update', $page), [
            'is_published' => 1,
            'template' => $page->template ?: 'default',
            'translations' => [
                'es' => [
                    'title' => 'Inicio ES', 'slug' => 'inicio',
                    'meta_title' => 'META ES', 'meta_description' => 'DESC ES', 'schema_type' => 'WebPage',
                ],
                'en' => [
                    'title' => 'Home EN', 'slug' => 'home-en',
                    'meta_title' => 'META EN', 'meta_description' => 'DESC EN', 'schema_type' => 'WebPage',
                ],
                'fr' => [
                    'title' => 'Accueil FR', 'slug' => 'accueil',
                    'meta_title' => 'META FR', 'meta_description' => 'DESC FR', 'schema_type' => 'WebPage',
                ],
            ],
        ])
        ->assertRedirect()->assertSessionHasNoErrors();

    $live = $page->fresh();
    expect($live->is_published)->toBeTrue();
    expect($live->translations->pluck('meta_title', 'locale')->all())->toBe($liveMeta);
    $draft = app(PageDomainServiceInterface::class)->editData($live)['page'];
    $meta = $draft->translations->pluck('meta_title', 'locale')->all();
    $this->get(route('admin.pages.edit', $page))->assertOk()->assertViewHas('page', fn (Page $editor): bool => $editor->translations->pluck('meta_title', 'locale')->all() === $meta);

    expect($meta['es'] ?? null)->toBe('META ES')
        ->and($meta['en'] ?? null)->toBe('META EN')
        ->and($meta['fr'] ?? null)->toBe('META FR');
    foreach (['es', 'en', 'fr'] as $locale) {
        $translation = $draft->translations->firstWhere('locale', $locale);
        expect($translation->meta_description)->toBe('DESC '.strtoupper($locale));
        expect($translation->schema_type)->toBe('WebPage');
    }
    app(EditorialWorkflow::class)->approve($live, $admin);
    expect($live->fresh()->translations->pluck('meta_title', 'locale')->all())->toBe($meta);
    expect($live->fresh()->is_published)->toBeTrue();
});

test('the SEO box submits nested metadata for active and inactive languages', function (): void {
    $page = Page::whereHas('translations')->firstOrFail();
    $admin = User::first();

    $html = $this->actingAs($admin, 'web')
        ->get(route('admin.pages.edit', $page))
        ->assertOk()
        ->getContent();

    // The visible fields follow the active tab; hidden fields submit every
    // locale using the same nested contract accepted by EditorialEntityRequest.
    foreach (['meta_title', 'meta_description', 'schema_type'] as $field) {
        expect($html)->toContain("'translations[' + locale + '][{$field}]'")
            ->toContain("'translations[' + code + '][{$field}]'")
            ->toContain("(translations[code] || {}).{$field}");
    }
    expect($html)->toContain('code in languages')
        ->not->toContain("'meta_title_' + code")
        ->not->toContain("'meta_description_' + code")
        ->not->toContain("'schema_type_' + code");
});
