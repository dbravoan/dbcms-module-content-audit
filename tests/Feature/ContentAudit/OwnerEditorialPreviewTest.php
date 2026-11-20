<?php

declare(strict_types=1);

use DbCms\Core\DTOs\Front\PublicContentContext;
use DbCms\Core\Models\Market;
use DbCms\Modules\Blog\Models\Post;
use DbCms\Modules\Blog\Models\PostTranslation;
use DbCms\Modules\Blog\Services\BlogEditorialPreview;
use Tests\Support\BlogAuditComposition;

uses(BlogAuditComposition::class);

test('blog preview selects snapshot translation rather than a live lookup', function (): void {
    $post = new Post;
    $post->setRelation('translations', collect([new PostTranslation(['locale' => 'es', 'title' => 'Versión pendiente'])]));
    $post->setRelation('sections', collect());
    $context = new PublicContentContext(new Market(['code' => 'es']), 'es');

    $preview = app(BlogEditorialPreview::class)->render($post, $context);

    expect($preview->data['post'])->toBe($post)
        ->and($preview->data['translation']->title)->toBe('Versión pendiente')
        ->and(app(BlogEditorialPreview::class)->render($post, new PublicContentContext($context->market, 'en'))->viewName)->toBeNull();
});
