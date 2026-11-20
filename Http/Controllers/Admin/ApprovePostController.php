<?php

declare(strict_types=1);

namespace DbCms\Modules\ContentAudit\Http\Controllers\Admin;

use DbCms\Core\Http\Controllers\Controller;
use DbCms\Modules\ContentAudit\Http\Adapters\EditorialReviewTransport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ApprovePostController extends Controller
{
    public function __construct(private readonly EditorialReviewTransport $transport) {}

    public function __invoke(Request $request, string $type, int $id): RedirectResponse
    {
        return $this->transport->approve($request, $type, $id);
    }
}
