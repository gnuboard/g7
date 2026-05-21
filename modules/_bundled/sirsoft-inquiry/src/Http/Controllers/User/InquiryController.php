<?php

namespace Modules\Sirsoft\Inquiry\Http\Controllers\User;

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
        $status = $request->query('status');
        $perPage = (int) $request->query('per_page', 20);

        $paginator = $this->inquiries->listByUser($request->user()->id, $status ?: null, $perPage);

        return InquiryResource::collection($paginator);
    }

    public function show(Request $request, Inquiry $inquiry)
    {
        $this->authorize('view', $inquiry);

        $inquiry->load(['quotes.items', 'attachments']);

        return new InquiryResource($inquiry);
    }
}
