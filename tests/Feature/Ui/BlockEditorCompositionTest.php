<?php

declare(strict_types=1);

use DbCms\Core\Blocks\BlockEditorCatalogue;
use DbCms\Core\Blocks\Contracts\BlockRegistryInterface;
use DbCms\Core\Contracts\Services\AdminThemeServiceInterface;
use DbCms\Core\Models\Language;
use DbCms\Core\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Js;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\CmsFixtures;
use Tests\Support\ReleaseSurfaceComposition;

uses(ReleaseSurfaceComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization();
    CmsFixtures::identities();
});

/** @return array{DOMDocument, DOMXPath} */
function blockEditorDom(string $html): array
{
    // libxml's HTML4 parser cannot tokenize Alpine's @ event shorthand.
    // Preserve the same browser expression through its supported long form.
    $html = preg_replace('/(\s)@([a-z][a-z0-9.:-]*)=/', '$1x-on:$2=', $html);
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    try {
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    return [$dom, new DOMXPath($dom)];
}

test('the registered catalogue and recursive editor mounts reach both brands', function (string $theme): void {
    app(AdminThemeServiceInterface::class)->setActiveTheme($theme);
    $registry = app(BlockRegistryInterface::class);
    $sections = [];
    foreach ($registry->getActiveBlocks() as $type => $definition) {
        $sections[] = ['type' => $type] + $definition->getDefaultPayload();
    }
    $languages = Language::where('is_active', true)->orderByDesc('is_default')->get();
    $html = Blade::render('<x-admin.block-manager :sections="$sections" :languages="$languages" name="sections_json" />', compact('sections', 'languages'));
    [, $xpath] = blockEditorDom($html);
    $root = $xpath->query('//*[@x-data][starts-with(@x-data, "blockManager(")]')->item(0);
    expect($root)->not->toBeNull();
    expect($xpath->query('//input[@type="hidden"][@name="sections_json"]')->item(0)->getAttribute(':value'))->toBe('getCleanSerializedSections()');
    // Defaults and complete recursive schemas are transferred as data. Native
    // controls are mounted by editor-runtime and exercised in the browser suite.
    $catalogue = app(BlockEditorCatalogue::class)->data();
    expect($root->getAttribute('x-data'))->toContain(Js::from($catalogue['availableTypesJs'])->toHtml());
    // Each card owns its content form: the root card and a column block card.
    $mounts = $xpath->query('//*[@data-schema-editor][contains(@x-effect, "mountSchemaEditor(")]');
    expect($mounts->length)->toBe(2, 'One root card content mount and one column block content mount.');
    foreach ($mounts as $mount) {
        expect($mount->getAttribute('x-effect'))->toContain("'content')");
        expect($xpath->query('./*', $mount->parentNode)->length)->toBe(1);
    }
    expect($html)->not->toContain('inspectorEntries')->not->toContain('inspectedBlock');
    // Design/Motion/Preview share one kit modal host each (not per-card mounts).
    expect($xpath->query('//*[@data-design-dialog]')->length)->toBe(1);
    expect($xpath->query('//*[@data-motion-dialog]')->length)->toBe(1);
    expect($xpath->query('//*[@data-add-block-dialog]')->length)->toBe(1);
    expect($xpath->query('//*[@data-templates-panel]')->length)->toBe(1);
    expect($xpath->query('//*[@data-template-preview-dialog]')->length)->toBe(1);
    expect($xpath->query('//*[@data-inspector-design]')->length)->toBe(1);
    expect($xpath->query('//*[@data-inspector-motion]')->length)->toBe(1);
    expect($xpath->query('//*[@role="tablist"][@aria-label="Opciones del bloque"]')->length)->toBe(0);
    expect($xpath->query('//*[@x-show][contains(@x-show, "showAppearanceModal")]')->length)->toBe(0);
    // Drag of roots and of column blocks, and a preview that is closed and outside the layout.
    expect($xpath->query('//*[@data-editor-root-list][@x-init="mountRootSortable($el)"]')->length)->toBe(1);
    expect($xpath->query('//*[@data-column-list][@x-init="mountColumnSortable($el, index, colIdx)"]')->length)->toBe(1);
    // Column Sortable requires each draggable node as the single x-for root (direct list child).
    foreach ($xpath->query('//template[contains(@x-for, "subBlock")]') as $loop) {
        expect($xpath->query('./*', $loop)->length)->toBe(1);
        expect($xpath->query('./*[@data-editor-node]', $loop)->length)->toBe(1);
    }
    expect($xpath->query('//*[@data-column-list]//button[contains(@class, "border-dashed")]')->length)->toBe(0);
    $preview = $xpath->query('//*[@data-composition-preview]');
    expect($preview->length)->toBe(1);
    expect($xpath->query('ancestor::*[@x-show="showPreview"]', $preview->item(0))->length)->toBe(1);
    expect($xpath->query('ancestor::*[@data-preview-dialog]', $preview->item(0))->length)->toBe(1);
    foreach ($xpath->query('//template[@x-for="(block, index) in blocks"]') as $loop) {
        expect($xpath->query('./*', $loop)->length)->toBe(1);
    }
    expect($xpath->query('//label//label')->length)->toBe(0);

    $assertFields = function (array $fields, string $path) use (&$assertFields): void {
        foreach ($fields as $key => $field) {
            $type = $field['type'] ?? 'text';
            expect($type)->toBeIn(['text', 'string', 'url', 'email', 'textarea', 'integer', 'number', 'boolean', 'select', 'media', 'json', 'array', 'list', 'color', 'blocks', 'object', 'repeater'], $path.'.'.$key.' needs a supported editor control.');
            if (in_array($type, ['object', 'repeater'], true)) {
                expect(isset($field['fields']) || ($type === 'object' && isset($field['fields_source'], $field['reference_field'])))->toBeTrue($path.'.'.$key.' needs its nested field definition.');
                $assertFields($field['fields'] ?? [], $path.'.'.$key);
            }
            if (isset($field['options_source'])) {
                expect($type)->toBe('select');
                expect($field)->toHaveKeys(['option_label', 'option_value']);
            }
        }
    };
    foreach ($registry->getActiveBlocks() as $type => $definition) {
        $assertFields($definition->getEditorSchema(), $type);
    }
})->with(['dbcms', 'termocms']);

test('empty and unavailable editor payloads stay intact and validation remains visible in both brands', function (string $theme): void {
    app(AdminThemeServiceInterface::class)->setActiveTheme($theme);
    $languages = Language::where('is_active', true)->orderByDesc('is_default')->get();
    $empty = Blade::render('<x-admin.block-manager :sections="[]" :languages="$languages" />', compact('languages'));
    [, $xpath] = blockEditorDom($empty);
    $root = $xpath->query('//*[@x-data][starts-with(@x-data, "blockManager(")]')->item(0);
    expect($root->getAttribute('x-data'))->toContain(Js::from(['es' => [], 'en' => []])->toHtml());
    $sections = ['es' => [['type' => 'unknown_preserved_block', 'private_value' => 'UNAVAILABLE_PAYLOAD']], 'en' => []];
    $unavailable = Blade::render('<x-admin.block-manager :sections="$sections" :languages="$languages" />', compact('sections', 'languages'));
    [, $xpath] = blockEditorDom($unavailable);
    $root = $xpath->query('//*[@x-data][starts-with(@x-data, "blockManager(")]')->item(0);
    expect($root->getAttribute('x-data'))->toContain(Js::from($sections)->toHtml());
    // The unavailable card shows its own placeholder; the design dialog guards the selection too.
    $placeholder = $xpath->query('//*[@x-text="unavailableBlockMessage(block)"]')->item(0);
    expect($placeholder)->not->toBeNull();
    expect($xpath->query('//*[@data-design-dialog]//*[@role="status"][@x-text="unavailableBlockMessage(selectedNode())"]')->item(0)?->getAttribute('x-show'))->toBe('!inspectorColumn() && !isBlockAvailable(selectedNode())');

    $errors = new ViewErrorBag;
    $errors->put('default', new MessageBag(['sections_json' => ['Contenido de bloques inválido.']]));
    $this->withSession(['errors' => $errors]);
    session()->save();
    $this->actingAs(User::where('email', 'admin@db-cms.io')->firstOrFail())
        ->withCookie((string) config('session.cookie'), session()->getId())
        ->get(route('admin.pages.create'))->assertOk()->assertSee('Contenido de bloques inválido.')->assertSee('name="sections_json"', false);
})->with(['dbcms', 'termocms']);
