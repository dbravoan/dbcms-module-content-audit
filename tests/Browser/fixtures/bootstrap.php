<?php

declare(strict_types=1);

use DbCms\Core\Content\Pages\Models\Page;
use DbCms\Core\Contracts\Services\ModuleSettingsServiceInterface;
use DbCms\Core\Models\Language;
use DbCms\Core\Models\Market;
use DbCms\Core\Models\User;
use DbCms\Modules\ContentAudit\Services\EditorialWorkflow;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$root = getenv('DBCMS_BROWSER_APP') ?: dirname(__DIR__, 3).'/.build/app';
if (getenv('DBCMS_BROWSER_FIXTURE') !== '1'
    || ! is_file($root.'/.dbcms-browser-disposable')
    || trim((string) file_get_contents($root.'/.dbcms-browser-disposable')) !== 'dbcms-module-browser'
    || preg_match('/^dbcms_browser_[a-z0-9_]+$/D', (string) getenv('DB_DATABASE')) !== 1) {
    throw new RuntimeException('Requires a marked disposable application and a dedicated browser database.');
}
putenv('CMS_MODULES=content_audit');
$_ENV['CMS_MODULES'] = $_SERVER['CMS_MODULES'] = 'content_audit';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE') || DB::transactionLevel() !== 0) {
    throw new RuntimeException('The browser fixture must use its committed disposable database.');
}
$processing = array_map(static fn (string $owner): array => [
    'owner' => $owner, 'purpose' => 'Disposable browser acceptance fixture', 'legal_basis' => 'Requested test service',
    'data' => 'Synthetic test data', 'retention' => 'Deleted after acceptance', 'retention_days' => 1,
    'recipients' => 'Disposable local application', 'transfers' => 'External browser requests are intercepted',
], ['core', 'forms', 'executions', 'content_audit']);
$companyFile = storage_path('browser-company.json');
file_put_contents($companyFile, json_encode([
    'company_name' => 'Browser fixture SL', 'tax_id' => 'TEST-BROWSER', 'activity' => 'Synthetic acceptance',
    'address' => 'Test street 1, Madrid', 'country' => 'ES', 'contact_email' => 'contact@example.test',
    'privacy_email' => 'privacy@example.test', 'hosting_provider' => 'Disposable Docker fixture', 'hosting_country' => 'ES',
    'processing' => $processing, 'reviewed' => true,
], JSON_THROW_ON_ERROR));
$exit = Artisan::call('cms:install', [
    '--db-host' => getenv('DB_HOST'), '--db-port' => getenv('DB_PORT') ?: '3306', '--db-database' => getenv('DB_DATABASE'),
    '--db-username' => getenv('DB_USERNAME'), '--db-password' => getenv('DB_PASSWORD'), '--without-demo' => true,
    '--admin-name' => 'Browser administrator', '--admin-email' => 'browser@example.test', '--admin-password' => 'browser-only-password',
    '--company-file' => $companyFile, '--site-name' => 'Module browser acceptance', '--brand' => 'dbcms', '--theme' => 'default-clean', '--force' => true,
]);
echo Artisan::output();
if ($exit !== 0) {
    throw new RuntimeException('The disposable browser installation failed.');
}
$user = User::where('email', 'browser@example.test')->sole();
$settings = app(ModuleSettingsServiceInterface::class);
$spanish = Language::firstOrCreate(['code' => 'es'], ['name' => 'Español', 'native_name' => 'Español', 'is_default' => true, 'is_active' => true]);
$english = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'is_default' => false, 'is_active' => true]);
$market = Market::where('is_default', true)->firstOrFail();
$market->update(['code' => 'es', 'default_locale' => 'es', 'is_active' => true]);
$market->languages()->sync([$spanish->id => ['is_default' => true], $english->id => ['is_default' => false]]);
$otherMarket = Market::firstOrCreate(['code' => 'uk'], ['name' => 'Browser UK', 'currency' => 'GBP', 'default_locale' => 'en', 'is_active' => true]);
$otherMarket->languages()->sync([$spanish->id => ['is_default' => false], $english->id => ['is_default' => true]]);
$fixtures = ['modules' => ['content_audit'], 'secondMarket' => $otherMarket->id];
$settings->set('content_audit', 'notify_email', false);
$settings->set('content_audit', 'enforce_editorial_quality', false);
$settings->set('content_audit', 'auto_publish_on_approval', true);
foreach (['dbcms', 'termocms'] as $brand) {
    foreach (['audit' => '', 'mobileAudit' => ' mobile'] as $fixtureKey => $suffix) {
        $page = Page::create(['template' => 'default', 'layout' => 'app', 'is_published' => false]);
        $page->translations()->createMany([
            ['locale' => 'es', 'title' => 'Browser audit '.$brand.$suffix, 'slug' => 'browser-audit-'.$brand.str_replace(' ', '-', $suffix)],
            ['locale' => 'en', 'title' => 'Browser audit English '.$brand.$suffix, 'slug' => 'browser-audit-en-'.$brand.str_replace(' ', '-', $suffix)],
        ]);
        $page->markets()->sync([$market->id, $otherMarket->id]);
        $section = $page->sections()->create(['type' => 'heading', 'order' => 0, 'is_active' => true, 'status' => 'published']);
        $section->translations()->createMany([
            ['locale' => 'es', 'content_json' => ['text' => 'Browser audit '.$brand.$suffix, 'level' => 'h1']],
            ['locale' => 'en', 'content_json' => ['text' => 'Browser audit English '.$brand.$suffix, 'level' => 'h1']],
        ]);
        $tabs = $page->sections()->create(['type' => 'tabs', 'order' => 1, 'is_active' => true, 'status' => 'published']);
        foreach (['es', 'en'] as $locale) {
            $tabs->translations()->create(['locale' => $locale, 'content_json' => [
                'label' => 'Browser review tabs', 'responsive_mode' => 'tabs', 'initial_index' => 0,
                'items' => [
                    ['title' => 'Datos A', 'children' => [['type' => 'text', 'content' => 'Browser tab first', 'format' => 'markdown']]],
                    ['title' => 'Datos B', 'children' => [['type' => 'text', 'content' => 'Browser tab second', 'format' => 'markdown']]],
                ],
            ]]);
        }
        app(EditorialWorkflow::class)->submitForReview($page, $user, 'Synthetic browser review');
        $fixtures[$brand][$fixtureKey] = $page->id;
    }
}
file_put_contents(storage_path('browser-fixtures.json'), json_encode($fixtures, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Browser fixture initialized.\n";
