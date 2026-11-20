<?php

declare(strict_types=1);

namespace DbCms\Modules\ContentAudit\Http\Controllers\Admin;

use DbCms\Core\Http\Controllers\Controller;
use DbCms\Modules\ContentAudit\Http\Adapters\EditorialReviewTransport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class RollbackPostController extends Controller
{
    public function __construct(private readonly EditorialReviewTransport $transport) {}

    public function __invoke(Request $request, int $revisionId): RedirectResponse
    {
        return $this->transport->rollback($request, $revisionId);
    }
}
