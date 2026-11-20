<?php

declare(strict_types=1);

namespace DbCms\Modules\ContentAudit\Contracts\Services;

use DbCms\Core\Models\User;
use DbCms\Modules\ContentAudit\Models\ContentAuditRevision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface EditorialVisibilityInterface
{
    public function canReview(User $user): bool;

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     */
    public function constrain(Builder $query, User $user): void;

    public function authorizeRevision(ContentAuditRevision $revision, User $user): void;
}
