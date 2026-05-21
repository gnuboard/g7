<?php

namespace Modules\Sirsoft\Inquiry\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Modules\Sirsoft\Inquiry\Http\Resources\InquiryResource;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Services\InquiryStateMachine;

class InquiryActionController extends Controller
{
    public function __construct(
        private readonly InquiryStateMachine $stateMachine,
    ) {}

    public function markPaidOffline(Request $request, Inquiry $inquiry)
    {
        $this->ensureOperator($request);

        $this->stateMachine->transition(
            $inquiry,
            TransitionEvent::MarkPaidOffline,
            actorUserId: $request->user()->id,
        );

        return new InquiryResource($inquiry->fresh()->load(['quotes.items', 'attachments']));
    }

    public function complete(Request $request, Inquiry $inquiry)
    {
        $this->ensureOperator($request);

        $this->stateMachine->transition(
            $inquiry,
            TransitionEvent::MarkCompleted,
            actorUserId: $request->user()->id,
        );

        return new InquiryResource($inquiry->fresh()->load(['quotes.items', 'attachments']));
    }

    public function cancel(Request $request, Inquiry $inquiry)
    {
        $this->ensureOperator($request);

        $this->stateMachine->transition(
            $inquiry,
            TransitionEvent::Cancel,
            actorUserId: $request->user()->id,
            payload: ['actor' => 'operator'],
        );

        return new InquiryResource($inquiry->fresh()->load(['quotes.items', 'attachments']));
    }

    private function ensureOperator(Request $request): void
    {
        if (! $request->user()?->can(config('inquiry.permissions.manage', 'inquiry.manage'))) {
            abort(403);
        }
    }
}
