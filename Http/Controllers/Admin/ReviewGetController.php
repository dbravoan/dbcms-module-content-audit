<?php

declare(strict_types=1);

namespace DbCms\Modules\ContentAudit\Http\Controllers\Admin;

use DbCms\Core\Http\Controllers\Controller;
use DbCms\Modules\ContentAudit\Http\Adapters\EditorialReviewTransport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ReviewGetController extends Controller
{
    public function __construct(private readonly EditorialReviewTransport $transport) {}

    public function __invoke(Request $request, string $type, int $id): View|RedirectResponse
    {
        return $this->transport->review($request, $type, $id);
    }
}
