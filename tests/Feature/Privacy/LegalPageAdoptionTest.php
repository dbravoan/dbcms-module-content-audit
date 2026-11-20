<?php

declare(strict_types=1);

use DbCms\Core\Content\Pages\Models\Page;
use DbCms\Core\Contracts\Services\GeneralSettingsServiceInterface;
use DbCms\Core\DTOs\Install\LegalCompanyDTO;
use DbCms\Core\Models\Market;
use DbCms\Core\Services\Capabilities\ContentSnapshotService;
use DbCms\Core\Services\Privacy\LegalPageService;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\Support\CmsFixtures;
use Tests\Support\ContentAuditComposition;
use Tests\Support\PrivacyFixtures;

uses(ContentAuditComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization(['es', 'en']);
});

function legacyLegalPage(string $slug = 'my-privacy'): Page
{
    $page = Page::create(['template' => 'default', 'is_published' => true]);
    foreach (['es', 'en'] as $locale) {
        $page->translations()->create(['locale' => $locale, 'title' => 'Reviewed '.$locale.' policy', 'slug' => $slug.'-'.$locale]);
    }
    $section = $page->sections()->create(['type' => 'text', 'is_active' => true, 'status' => 'published', 'styling_json' => []]);
    $section->translations()->create(['locale' => 'es', 'content_json' => ['content' => 'Previously approved custom legal prose']]);

    return $page->refresh();
}

test('explicit legal adoption preserves localized URLs canonical rows and historical snapshot bytes', function (): void {
    $page = legacyLegalPage();
    $page->translations()->create(['locale' => 'es', 'market_id' => Market::where('code', 'es')->value('id'), 'title' => 'Spain privacy policy', 'slug' => 'privacidad-local-es']);
    app(GeneralSettingsServiceInterface::class)->set('gdpr.page_privacy', 'my-privacy-es', 'gdpr');
    $snapshots = app(ContentSnapshotService::class);
    $before = $snapshots->capture($page);
    $history = $before + ['_live_hash' => $snapshots->hash($before)];
    $id = DB::table('content_audit_revisions')->insertGetId(['auditable_type' => $page->getMorphClass(), 'auditable_id' => $page->id, 'action' => 'updated', 'title' => 'Historical policy', 'snapshot' => json_encode($history, JSON_THROW_ON_ERROR)]);
    $stored = DB::table('content_audit_revisions')->where('id', $id)->value('snapshot');
    $directory = sys_get_temp_dir().'/dbcms-legal-adoption-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($directory, 0700);
    $environment = app()->environmentPath();
    try {
        File::put($directory.'/.env', 'APP_KEY='.(string) config('app.key')."\nLOG_CHANNEL=stack\n");
        app()->useEnvironmentPath($directory);
        File::put($directory.'/company.json', json_encode(PrivacyFixtures::company(), JSON_THROW_ON_ERROR));
        File::put($directory.'/pages.json', json_encode(['privacy' => $page->id], JSON_THROW_ON_ERROR));
        $this->artisan('privacy:configure', ['file' => $directory.'/company.json', '--adopt-legal-pages' => $directory.'/pages.json'])->assertSuccessful();
        $values = Dotenv::parse(File::get($directory.'/.env'));
        expect($values['LOG_CHANNEL'])->toBe('daily')
            ->and((int) $values['LOG_DAILY_DAYS'])->toBe((int) app(LegalPageService::class)->requireProcessing('core')['retention_days'])
            ->and(config('logging.channels.daily.days'))->toBe((int) $values['LOG_DAILY_DAYS']);
    } finally {
        app()->useEnvironmentPath($environment);
        File::deleteDirectory($directory);
    }
    $after = $snapshots->capture($page->refresh());
    expect($page->template)->toBe('blade:legal.privacy')
        ->and($after['translation_records'])->toBe($before['translation_records'])
        ->and($after['section_records'])->toBe($before['section_records'])
        ->and($after['market_pivots'])->toBe($before['market_pivots'])
        ->and(DB::table('content_audit_revisions')->where('id', $id)->value('snapshot'))->toBe($stored)
        ->and($snapshots->hash($after))->not->toBe($history['_live_hash'])
        ->and(setting('gdpr.page_privacy'))->toBe('my-privacy-es');
    $this->withoutVite();
    $this->get('/es/es/privacidad-local-es')->assertOk()->assertSee('Example Test SL')->assertDontSee('Previously approved custom legal prose');
    $this->get('/es/en/my-privacy-en')->assertOk()->assertSee('Example Test SL');
});

test('configured existing legal candidates require explicit adoption before any write', function (): void {
    $page = legacyLegalPage();
    app(GeneralSettingsServiceInterface::class)->set('gdpr.page_privacy', 'my-privacy-es', 'gdpr');
    expect(fn () => app(LegalPageService::class)->provision(LegalCompanyDTO::fromArray(PrivacyFixtures::company())))->toThrow(ValidationException::class, 'explicit --adopt-legal-pages');
    expect($page->fresh()->template)->toBe('default')->and(Page::count())->toBe(1)
        ->and(DB::table('settings')->where('key', 'legal.company_name')->exists())->toBeFalse();
});

test('adoption rejects unknown roles invalid IDs and reused pages without changing content', function (string $case): void {
    $page = legacyLegalPage();
    $mapping = match ($case) {
        'role' => ['terms' => $page->id],
        'missing' => ['privacy' => $page->id + 1000],
        'invalid' => ['privacy' => (string) $page->id],
        'duplicate' => ['notice' => $page->id, 'privacy' => $page->id],
    };
    expect(fn () => app(LegalPageService::class)->provision(LegalCompanyDTO::fromArray(PrivacyFixtures::company()), $mapping))->toThrow(ValidationException::class);
    expect($page->fresh()->template)->toBe('default')->and(Page::count())->toBe(1)
        ->and(DB::table('settings')->where('key', 'legal.company_name')->exists())->toBeFalse();
})->with(['role', 'missing', 'invalid', 'duplicate']);

test('adoption refuses a localized slug collision before applying company settings or templates', function (): void {
    $page = legacyLegalPage();
    legacyLegalPage();
    expect(fn () => app(LegalPageService::class)->provision(LegalCompanyDTO::fromArray(PrivacyFixtures::company()), ['privacy' => $page->id]))->toThrow(ValidationException::class, 'already used');
    expect($page->fresh()->template)->toBe('default')
        ->and(DB::table('settings')->where('key', 'legal.company_name')->exists())->toBeFalse();
});
