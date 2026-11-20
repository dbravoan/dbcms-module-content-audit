<?php

declare(strict_types=1);

namespace DbCms\Modules\ContentAudit\Services;

use DbCms\Core\Capabilities\Contracts\ContentRevisionInspectorInterface;
use DbCms\Modules\ContentAudit\Models\ContentAudit;
use Illuminate\Database\Eloquent\Model;

final class ContentRevisionInspector implements ContentRevisionInspectorInterface
{
    public function __construct(private readonly EditorialPolicy $policy) {}

    public function fingerprint(Model $entity): ?array
    {
        if (! $this->policy->isEnabled()) {
            return null;
        }

        $audit = ContentAudit::query()->where('auditable_type', $entity->getMorphClass())->where('auditable_id', $entity->getKey())->first();

        return $audit?->pending_revision_id === null ? null : $audit->revisions()->whereKey($audit->pending_revision_id)->first()?->only(['id', 'snapshot']);
    }

    public function preview(Model $entity): ?array
    {
        $revision = $this->fingerprint($entity);
        if ($revision === null) {
            return null;
        }

        return ['revision_id' => $revision['id'], 'attributes' => $revision['snapshot']['attributes'] ?? [], 'translations' => $revision['snapshot']['translation_records'] ?? [], 'sections' => $revision['snapshot']['section_records'] ?? [], 'markets' => $revision['snapshot']['market_pivots'] ?? [], 'related_content' => $revision['snapshot']['module_relations'] ?? []];
    }
}
