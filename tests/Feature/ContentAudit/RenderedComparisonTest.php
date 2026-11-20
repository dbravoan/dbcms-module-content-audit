<?php

declare(strict_types=1);

use DbCms\Core\Models\Language;
use DbCms\Core\Models\Market;
use DbCms\Core\Models\User;
use DbCms\Modules\ContentAudit\Contracts\Services\AuditWorkbenchServiceInterface;
use DbCms\Modules\ContentAudit\Services\AuditRevisionService;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\Support\CmsFixtures;
use Tests\Support\ContentAuditComposition;

uses(ContentAuditComposition::class);

beforeEach(function (): void {
    $this->withoutVite();
    CmsFixtures::localization(['es', 'en'], ['es', 'us']);
    CmsFixtures::identities();
    Market::where('code', 'us')->sole()->languages()->sync([Language::where('code', 'en')->sole()->id]);
    Market::create(['code' => 'zz', 'name' => 'Inactive market', 'currency' => 'EUR', 'default_locale' => 'es', 'is_active' => false]);
});

test('the workbench prepares active market choices and their own languages before rendering', function (): void {
    $options = app(AuditWorkbenchServiceInterface::class)->comparisonOptions();
    $markets = $options['previewMarkets'];
    expect(array_column($markets, 'name'))->toBe(['es', 'us'])
        ->and(array_column($markets[0]['languages'], 'code'))->toBe(['es', 'en'])
        ->and(array_column($markets[1]['languages'], 'code'))->toBe(['en'])
        ->and($options['previewMarket'])->toBe($markets[0]['id'])
        ->and($options['previewLocale'])->toBe('es');

    $context = $options + ['type' => 'pages', 'id' => 1, 'previewParams' => ['token' => 'opaque-review-token']];
    $template = file_get_contents(view('content_audit::admin.partials.rendered-comparison')->getPath());
    // Isolate this template from the shared layout composers; HTTP coverage follows.
    DB::enableQueryLog();
    DB::flushQueryLog();
    Blade::render($template, $context);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(collect($queries)->filter(static fn (array $query): bool => preg_match('/(?:from|join) `(?:markets|languages)`/', $query['query']) === 1)->pluck('query')->all())->toBe([]);
});

test('review and revision diff share a lazy opaque comparison in the admin modal stack', function (): void {
    $page = CmsFixtures::home();
    $admin = User::where('email', 'admin@db-cms.io')->sole();
    $revision = app(AuditRevisionService::class)->record($page, $admin, 'created');
    foreach ([route('admin.audit.review', ['type' => 'pages', 'id' => $page->id]), route('admin.audit.diff', $revision->id)] as $url) {
        $response = $this->actingAs($admin)->get($url)->assertOk()->assertViewHas('previewMarkets')->assertViewHas('previewMarket')->assertViewHas('previewLocale');
        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        expect($xpath->query('//button[@data-audit-comparison-open]')->length)->toBe(1)
            ->and($xpath->query('//div[@data-audit-comparison][@data-admin-dialog]')->length)->toBe(1)
            ->and($xpath->query('//main//*[@data-audit-comparison]')->length)->toBe(0)
            ->and($xpath->query('//*[@data-audit-comparison]//template[@x-if="comparisonOpen"]//iframe')->length)->toBe(2);
        foreach ($xpath->query('//*[@data-audit-comparison]//iframe') as $frame) {
            expect($frame->getAttribute('sandbox'))->toBe('allow-scripts')
                ->and($frame->hasAttribute('src'))->toBeFalse();
        }
        expect($response->getContent())->not->toContain('contain: \'paint\'', 'full = !full', 'fixed inset-0 z-[100]');
    }
});

test('the workbench represents absence of active markets without invalid default selections', function (): void {
    Market::query()->update(['is_active' => false]);
    expect(app(AuditWorkbenchServiceInterface::class)->comparisonOptions())->toBe(['previewMarkets' => [], 'previewMarket' => '', 'previewLocale' => '']);
});
