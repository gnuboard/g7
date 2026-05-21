<?php

namespace Modules\Sirsoft\Inquiry\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Sirsoft\Inquiry\Http\Resources\InquiryResource;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryRepositoryInterface;

class InquiryController extends Controller
{
    public function __construct(
        private readonly InquiryRepositoryInterface $inquiries,
    ) {}

    public function index(Request $request)
    {
        $this->authorizePermission($request);

        $status = $request->query('status');
        $search = $request->query('search');
        $perPage = (int) $request->query('per_page', 20);

        $paginator = $this->inquiries->listForAdmin(
            $status ?: null,
            $search ?: null,
            $perPage
        );

        return InquiryResource::collection($paginator);
    }

    public function show(Request $request, Inquiry $inquiry)
    {
        $this->authorizePermission($request);
        $inquiry->load(['quotes.items', 'attachments', 'user']);
        return new InquiryResource($inquiry);
    }

    private function authorizePermission(Request $request): void
    {
        if (! $request->user()?->can(config('inquiry.permissions.manage', 'inquiry.manage'))) {
            abort(403);
        }
    }
}
