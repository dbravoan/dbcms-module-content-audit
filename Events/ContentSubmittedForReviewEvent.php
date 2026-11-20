<?php

declare(strict_types=1);

namespace DbCms\Modules\ContentAudit\Events;

use DbCms\Core\Models\User;
use DbCms\Modules\ContentAudit\Models\ContentAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContentSubmittedForReviewEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Model $entity,
        public ContentAudit $audit,
        public User $author,
        public ?string $notes = null
    ) {}
}
