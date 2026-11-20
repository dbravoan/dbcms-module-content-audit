<?php

declare(strict_types=1);

namespace DbCms\Modules\ContentAudit\Services;

use Carbon\CarbonInterface;
use DbCms\Core\Services\Privacy\LegalPageService;
use DbCms\Modules\ContentAudit\Models\ContentAudit;
use DbCms\Modules\ContentAudit\Models\ContentAuditRevision;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/** Retires whole owned records; never edits the meaning of a retained snapshot. */
final class AuditPrivacyService
{
    /** @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    public function export(string $kind, array $ids): array
    {
        return $kind === 'histories'
            ? array_values(ContentAudit::query()->whereIn('id', $ids)->with('revisions')->orderBy('id')->get()->map(static fn (ContentAudit $audit): array => $audit->toArray())->all())
            : array_values(ContentAuditRevision::query()->whereIn('id', $ids)->with('audit')->orderBy('id')->get()->map(static fn (ContentAuditRevision $revision): array => $revision->toArray())->all());
    }

    /** @param list<int> $ids */
    public function eraseRevisions(array $ids, bool $retentionReleased = false): int
    {
        $cutoff = $this->cutoff($retentionReleased);

        return DB::transaction(function () use ($ids, $cutoff): int {
            $revisions = ContentAuditRevision::query()->whereIn('id', $ids)->orderBy('id')->get();
            foreach ($revisions as $revision) {
                $this->lockOwner($revision->auditable_type, $revision->auditable_id);
                $audit = ContentAudit::query()->where('auditable_type', $revision->auditable_type)->where('auditable_id', $revision->auditable_id)->lockForUpdate()->first();
                $history = ContentAuditRevision::query()->where('auditable_type', $revision->auditable_type)->where('auditable_id', $revision->auditable_id)->orderByDesc('version')->lockForUpdate()->get();
                $protected = $history->take(2)->modelKeys();
                if ($audit?->pending_revision_id !== null || $audit?->status === 'pending_review'
                    || in_array($revision->id, $protected, true) || $revision->version === $audit?->current_version
                    || $revision->created_at->greaterThan($cutoff)) {
                    throw new DomainException('A revision is pending, retained for restoration, or within its retention period; nothing was erased.');
                }
            }

            return ContentAuditRevision::query()->whereIn('id', $revisions->modelKeys())->delete();
        });
    }

    /** Whole history release is reserved for a deleted owner after explicit review.
     * @param  list<int>  $ids
     */
    public function eraseHistories(array $ids, bool $retentionReleased = false): int
    {
        if (! $retentionReleased) {
            throw new DomainException('Releasing a complete deleted-content history requires documented retention review.');
        }
        $cutoff = $this->cutoff(true);

        return DB::transaction(function () use ($ids, $cutoff): int {
            $audits = ContentAudit::query()->whereIn('id', $ids)->orderBy('id')->get();
            foreach ($audits as $audit) {
                if ($this->lockOwner($audit->auditable_type, $audit->auditable_id) !== null) {
                    throw new DomainException('The content still exists; its restoration history cannot be erased.');
                }
                $audit = ContentAudit::query()->whereKey($audit->id)->lockForUpdate()->firstOrFail();
                if ($audit->status !== 'deleted' || $audit->pending_revision_id !== null || $audit->updated_at === null || $audit->updated_at->greaterThan($cutoff)
                    || $audit->revisions()->where('created_at', '>', $cutoff)->exists()) {
                    throw new DomainException('A history is still pending or retained; nothing was erased.');
                }
            }

            return ContentAudit::query()->whereIn('id', $audits->modelKeys())->delete();
        });
    }

    public function prune(): int
    {
        $cutoff = $this->cutoff(false);
        $count = 0;
        ContentAuditRevision::query()->where('created_at', '<=', $cutoff)->orderBy('id')->chunkById(100, function ($revisions) use (&$count): void {
            foreach ($revisions as $revision) {
                try {
                    $count += $this->eraseRevisions([$revision->id]);
                } catch (DomainException) {
                    // Pending and restoration-protected records remain available for review.
                }
            }
        });

        return $count;
    }

    private function cutoff(bool $retentionReleased): CarbonInterface
    {
        $policy = app(LegalPageService::class)->requireProcessing('content_audit', true);
        $days = (int) ($policy['retention_days'] ?? 0);
        if ($days < 1 && ! $retentionReleased) {
            throw new DomainException('This history uses manual retention review; supply explicit IDs and a documented release.');
        }

        // A review token can authorize an exact revision for thirty minutes.
        return $days > 0 ? now()->subDays($days) : now()->subMinutes(30);
    }

    private function lockOwner(string $type, int $id): ?Model
    {
        $class = Relation::getMorphedModel($type) ?? $type;
        if (! is_subclass_of($class, Model::class)) {
            throw new DomainException('The owning entity package is unavailable; review its history before erasure.');
        }

        return (new $class)->newQueryWithoutScopes()->whereKey($id)->lockForUpdate()->first();
    }
}
