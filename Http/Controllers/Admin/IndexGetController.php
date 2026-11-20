<?php

declare(strict_types=1);

namespace DbCms\Modules\ContentAudit\Http\Controllers\Admin;

use DbCms\Core\Http\Controllers\Controller;
use DbCms\Core\Models\User;
use DbCms\Modules\ContentAudit\Contracts\Services\AuditWorkbenchServiceInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class IndexGetController extends Controller
{
    public function __construct(private readonly AuditWorkbenchServiceInterface $workbench) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return view('content_audit::admin.index', $this->workbench->index($user, $request->query()));
    }
}
