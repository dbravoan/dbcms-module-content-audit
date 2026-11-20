<?php

declare(strict_types=1);

namespace DbCms\Modules\ContentAudit\Http\Controllers\Admin;

use DbCms\Core\Content\Contracts\EntitySchemaRegistryInterface;
use DbCms\Core\DTOs\Front\PublicContentContext;
use DbCms\Core\Http\Middleware\SecurityHeaders;
use DbCms\Core\Models\Market;
use DbCms\Core\Models\User;
use DbCms\Core\Services\Capabilities\ContentSnapshotService;
use DbCms\Modules\ContentAudit\Contracts\Services\EditorialVisibilityInterface;
use DbCms\Modules\ContentAudit\Models\ContentAuditRevision;
use DbCms\Modules\ContentAudit\Services\AuditEntityPresentation;
use DbCms\Modules\ContentAudit\Services\AuditRevisionService;
use DbCms\Modules\ContentAudit\Services\EditorialApprovalBinding;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PreviewGetController
{
    public function __invoke(Request $request, string $type, int $id): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $entity = app(AuditEntityPresentation::class)->find($type, $id);
        abort_unless($entity !== null, 404);
        $side = $request->query('side');
        abort_unless(in_array($side, ['before', 'after'], true), 404);
        $revisionId = $request->query('revision');
        if (is_numeric($revisionId)) {
            $target = ContentAuditRevision::findOrFail((int) $revisionId);
            app(EditorialVisibilityInterface::class)->authorizeRevision($target, $user);
            abort_unless($target->auditable_type === $entity->getMorphClass() && (int) $target->auditable_id === $id, 403);
            $revision = $target;
            if ($side === 'before') {
                $compareId = $request->query('compare_with');
                $revision = is_numeric($compareId) ? ContentAuditRevision::findOrFail((int) $compareId) : ContentAuditRevision::where('auditable_type', $target->auditable_type)->where('auditable_id', $id)->where('version', '<', $target->version)->orderByDesc('version')->first();
                if ($revision !== null) {
                    app(EditorialVisibilityInterface::class)->authorizeRevision($revision, $user);
                    abort_unless($revision->auditable_type === $target->auditable_type && (int) $revision->auditable_id === $id, 403);
                }
            }
            if ($revision === null) {
                return response('Sin versión anterior.', 200)->header('Cache-Control', 'private, no-store');
            }
            $snapshot = (array) $revision->snapshot;
        } else {
            $review = app(EditorialApprovalBinding::class)->preview($entity, $user, (string) $request->query('token'));
            $audit = app(AuditRevisionService::class)->auditFor($entity);
            if ($side === 'after') {
                $snapshot = $review['snapshot'];
            } elseif ($audit?->pending_revision_id !== null) {
                $snapshot = app(ContentSnapshotService::class)->capture($entity);
            } else {
                $previous = ContentAuditRevision::where('auditable_type', $entity->getMorphClass())->where('auditable_id', $id)->orderByDesc('version')->skip(1)->first() ?? ContentAuditRevision::where('auditable_type', $entity->getMorphClass())->where('auditable_id', $id)->orderByDesc('version')->first();
                if ($previous === null) {
                    return response('Sin versión anterior.', 200)->header('Cache-Control', 'private, no-store');
                }
                $snapshot = (array) $previous->snapshot;
            }
        }
        $market = Market::where('is_active', true)->whereKey($request->query('market'))->with('languages')->firstOrFail();
        $locale = (string) $request->query('locale');
        abort_unless($market->languages->contains('code', $locale), 404);
        app()->setLocale($locale);
        app()->instance('current.market', $market);
        $schema = collect(app(EntitySchemaRegistryInterface::class)->all())->first(fn ($schema) => $entity instanceof $schema->model);
        abort_unless($schema !== null && $schema->previewHandler !== null, 404);
        $draft = app(ContentSnapshotService::class)->hydrate($entity, $snapshot);
        $request->attributes->set('cms.editorial_preview', true);
        $data = app($schema->previewHandler)->render($draft, new PublicContentContext($market, $locale, currentUrl: $request->url()));
        abort_if($data->viewName === null, 404, 'No hay contenido disponible para este idioma y mercado.');

        return response()->view($data->viewName, $data->data)->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer')->header('Content-Security-Policy', app(SecurityHeaders::class)->previewPolicy());
    }
}
