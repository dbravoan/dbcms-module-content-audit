<?php

declare(strict_types=1);

namespace DbCms\Modules\ContentAudit\Http\Controllers\Admin;

use DbCms\Core\Http\Controllers\Controller;
use DbCms\Core\Models\User;
use DbCms\Modules\ContentAudit\Contracts\Services\AuditWorkbenchServiceInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DiffGetController extends Controller
{
    public function __construct(private readonly AuditWorkbenchServiceInterface $workbench) {}

    public function __invoke(Request $request, int $revisionId): JsonResponse|View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $diff = $this->workbench->diff($user, $revisionId, $request->query('compare_with'));

        if ($request->wantsJson()) {
            return response()->json($diff->jsonData());
        }

        return view('content_audit::admin.diff', $diff->viewData() + ['type' => $diff->targetRevision->auditable_type, 'id' => $diff->targetRevision->auditable_id] + $this->workbench->comparisonOptions());
    }
}
