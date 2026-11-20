<?php

declare(strict_types=1);

use DbCms\Core\DTOs\Front\PublicContentContext;
use DbCms\Core\Forms\Models\CustomForm;
use DbCms\Core\Forms\Models\CustomFormTranslation;
use DbCms\Core\Forms\Services\FormEditorialPreview;
use DbCms\Core\Forms\Services\FormMutationHandler;
use DbCms\Core\Models\Market;
use Tests\Support\FormsEditorialComposition;

uses(FormsEditorialComposition::class);

test('form preview uses hydrated fields and does not borrow another language or market', function (): void {
    $market = new Market;
    $market->id = 1;
    $form = new CustomForm;
    $form->setRawAttributes(['id' => 1, 'slug' => 'contact']);
    $translation = new CustomFormTranslation;
    $translation->setRawAttributes(['locale' => 'es', 'market_id' => null, 'name' => 'Formulario pendiente', 'submit_button_text' => 'Enviar']);
    $form->setRelation('translations', $translation->newCollection([$translation]));
    app(FormMutationHandler::class)->hydrateRevision($form, ['fields' => [[
        'attributes' => ['id' => 7, 'field_key' => 'name', 'type' => 'text', 'is_required' => true, 'validation_rules' => 'required'],
        'translations' => [
            ['locale' => 'es', 'market_id' => null, 'label' => 'Nombre pendiente', 'placeholder' => null, 'options' => null],
            ['locale' => 'es', 'market_id' => 2, 'label' => 'Otro mercado', 'placeholder' => null, 'options' => null],
        ],
    ]]]);

    $handler = app(FormEditorialPreview::class);
    $preview = $handler->render($form, new PublicContentContext($market, 'es'));
    expect($preview->data['formTitle'])->toBe('Formulario pendiente')
        ->and($preview->data['schema'][0]->label)->toBe('Nombre pendiente')
        ->and($preview->viewName)->toBe('forms::preview')
        ->and($handler->render($form, new PublicContentContext($market, 'en'))->viewName)->toBeNull();
});
