<?php

declare(strict_types=1);

namespace DbCms\Modules\ContentAudit\Http\Controllers\Admin;

use DbCms\Core\Http\Controllers\Controller;
use DbCms\Modules\ContentAudit\Contracts\Services\AuditWorkbenchServiceInterface;
use DbCms\Modules\ContentAudit\Http\Requests\AuditSettingsRequest;
use Illuminate\Http\RedirectResponse;

final class SettingsPostController extends Controller
{
    public function __construct(private readonly AuditWorkbenchServiceInterface $workbench) {}

    public function __invoke(AuditSettingsRequest $request): RedirectResponse
    {
        $this->workbench->updateSettings($request->validated());

        return redirect()->route('admin.audit.index', ['tab' => 'settings'])->with('success', 'Políticas de auditoría guardadas.');
    }
}
