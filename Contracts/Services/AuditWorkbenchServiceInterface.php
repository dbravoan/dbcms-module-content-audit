<?php

declare(strict_types=1);

namespace DbCms\Modules\ContentAudit\Contracts\Services;

use DbCms\Core\Models\User;
use DbCms\Modules\ContentAudit\DTOs\AuditDiffData;
use Illuminate\Database\Eloquent\Model;

interface AuditWorkbenchServiceInterface
{
    /** @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function index(User $user, array $filters): array;

    public function diff(User $user, int $revisionId, mixed $compareWithId): AuditDiffData;

    /** @return array{previewMarkets:list<array{id:string,name:string,languages:list<array{code:string,name:string}>}>,previewMarket:string,previewLocale:string} */
    public function comparisonOptions(): array;

    /** @param array<string,mixed> $values */
    public function updateSettings(array $values): void;

    /** @return array<string,mixed> */
    public function sidebar(Model $entity, ?User $user): array;
}
