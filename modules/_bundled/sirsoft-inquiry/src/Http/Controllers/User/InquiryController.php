<?php

namespace Modules\Sirsoft\Inquiry\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Modules\Sirsoft\Inquiry\Http\Requests\StoreInquiryRequest;
use Modules\Sirsoft\Inquiry\Http\Requests\UpdateInquiryRequest;
use Modules\Sirsoft\Inquiry\Http\Resources\InquiryResource;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryRepositoryInterface;
use Modules\Sirsoft\Inquiry\Services\InquiryStateMachine;

class InquiryController extends Controller
{
    public function __construct(
        private readonly InquiryRepositoryInterface $inquiries,
        private readonly InquiryStateMachine $stateMachine,
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

    public function store(StoreInquiryRequest $request)
    {
        $inquiry = $this->inquiries->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $request->user()->id,
            'title' => $request->string('title'),
            'content' => $request->string('content'),
            'category' => $request->input('category'),
            'budget_range' => $request->input('budget_range'),
            'desired_due_at' => $request->input('desired_due_at'),
            'status' => 'received',
        ]);

        // 운영자 그룹에 신규 의뢰 알림
        $operators = \App\Models\User::whereHas('roles.permissions', function ($q) {
            $q->where('identifier', config('inquiry.permissions.notify', 'inquiry.notify'));
        })->get();
        if ($operators->isNotEmpty()) {
            \Illuminate\Support\Facades\Notification::send($operators, new \Modules\Sirsoft\Inquiry\Notifications\InquiryReceivedToOperators($inquiry));
        }

        return (new InquiryResource($inquiry->load(['quotes.items', 'attachments'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateInquiryRequest $request, Inquiry $inquiry)
    {
        $this->inquiries->update($inquiry, $request->validated());
        return new InquiryResource($inquiry->fresh()->load(['quotes.items', 'attachments']));
    }

    public function cancel(Request $request, Inquiry $inquiry)
    {
        $this->authorize('cancel', $inquiry);

        $this->stateMachine->transition(
            $inquiry,
            TransitionEvent::Cancel,
            actorUserId: $request->user()->id,
            payload: ['actor' => 'client'],
        );

        return new InquiryResource($inquiry->fresh()->load(['quotes.items', 'attachments']));
    }
}
