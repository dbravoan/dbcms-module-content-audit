<?php

declare(strict_types=1);

use DbCms\Core\Content\Pages\Contracts\PageDomainServiceInterface;
use DbCms\Core\Content\Pages\Models\Page;
use DbCms\Core\Models\Market;
use DbCms\Core\Models\User;
use DbCms\Core\Sections\SectionEditorService;
use DbCms\Modules\ContentAudit\Services\EditorialWorkflow;
use Tests\Support\CmsFixtures;
use Tests\Support\ContentAuditComposition;

uses(ContentAuditComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization(['es', 'en', 'ca']);
    CmsFixtures::identities();
    $this->marketEs = Market::where('code', 'es')->firstOrFail();
    $this->admin = User::where('email', 'admin@db-cms.io')->firstOrFail();
});

test('saving explicit localized layouts preserves every requested language through review', function (): void {
    $page = Page::create([
        'template' => 'default',
        'layout' => 'app',
        'is_published' => true,
    ]);
    $page->markets()->sync([$this->marketEs->id]);

    $liveSections = app(SectionEditorService::class)->payload($page);

    $payload = [
        'template' => 'default',
        'layout' => 'app',
        'market_ids' => [$this->marketEs->id],
        'translations' => [
            'es' => ['title' => 'Página Multilingüe', 'slug' => 'pagina-multi'],
            'en' => ['title' => 'Multilingual Page', 'slug' => 'multilingual-page'],
            'ca' => ['title' => 'Pàgina Multilingüe', 'slug' => 'pagina-multilingue'],
        ],
        'sections_json' => json_encode(array_fill_keys(['es', 'en', 'ca'], [
            ['type' => 'heading', 'text' => 'Título compartido', 'level' => 'h1'],
            ['type' => 'list', 'items' => [['text' => 'Característica base']]],
        ]), JSON_THROW_ON_ERROR),
    ];

    $response = $this->actingAs($this->admin, 'web')->put(route('admin.pages.update', $page), $payload);
    $response->assertRedirect(route('admin.pages.index'));

    $response->assertSessionHasNoErrors();
    $live = $page->fresh();
    expect($live->is_published)->toBeTrue();
    expect(app(SectionEditorService::class)->payload($live))->toBe($liveSections);
    $page = app(PageDomainServiceInterface::class)->editData($live)['page'];
    $pendingSections = app(SectionEditorService::class)->payload($page);
    expect($page->sections)->toHaveCount(2);

    $headingSection = $page->sections->where('type', 'heading')->first();
    expect($headingSection)->not->toBeNull();
    // Verify each active language has a SectionTranslation
    expect($headingSection->translations)->toHaveCount(3);
    expect($headingSection->translations->pluck('locale')->all())->toEqualCanonicalizing(['es', 'en', 'ca']);

    $listSection = $page->sections->where('type', 'list')->first();
    expect($listSection)->not->toBeNull();
    expect($listSection->translations)->toHaveCount(3);
    expect($listSection->translations->pluck('locale')->all())->toEqualCanonicalizing(['es', 'en', 'ca']);
    app(EditorialWorkflow::class)->approve($live, $this->admin);
    expect(app(SectionEditorService::class)->payload($live->fresh()))->toBe($pendingSections);
    expect($live->fresh()->is_published)->toBeTrue();
});
