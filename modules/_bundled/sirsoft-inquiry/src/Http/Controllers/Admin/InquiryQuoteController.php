<?php

namespace Modules\Sirsoft\Inquiry\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Modules\Sirsoft\Inquiry\Http\Requests\IssueQuoteRequest;
use Modules\Sirsoft\Inquiry\Http\Resources\InquiryQuoteResource;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryQuote;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryQuoteRepositoryInterface;
use Modules\Sirsoft\Inquiry\Services\InquiryStateMachine;

class InquiryQuoteController extends Controller
{
    public function __construct(
        private readonly InquiryQuoteRepositoryInterface $quotes,
        private readonly InquiryStateMachine $stateMachine,
    ) {}

    public function issue(IssueQuoteRequest $request, Inquiry $inquiry)
    {
        $this->ensureOperator($request);

        $items = collect($request->input('items'))
            ->values()
            ->map(fn ($i, $idx) => [
                'position' => $idx + 1,
                'name' => $i['name'],
                'description' => $i['description'] ?? null,
                'qty' => $i['qty'],
                'unit_price' => $i['unit_price'],
                'amount' => round($i['qty'] * $i['unit_price']),
            ])
            ->all();

        $totalAmount = array_sum(array_column($items, 'amount'));
        $taxAmount = (int) ($request->input('tax_amount') ?? 0);

        $quote = $this->quotes->issue(
            $inquiry,
            [
                'total_amount' => $totalAmount,
                'tax_amount' => $taxAmount,
                'currency' => $request->input('currency') ?? config('inquiry.quote.currency', 'KRW'),
                'valid_until' => $request->input('valid_until'),
                'note' => $request->input('note'),
            ],
            $items,
        );

        // Inquiry status transition (only if currently received; if quoted, expireActive already handled)
        if ($inquiry->status->value === 'received') {
            $this->stateMachine->transition(
                $inquiry,
                TransitionEvent::IssueQuote,
                actorUserId: $request->user()->id,
                payload: ['quote_version' => $quote->version, 'quote_total' => $totalAmount],
            );
        }

        return (new InquiryQuoteResource($quote->load('items')))
            ->response()
            ->setStatusCode(201);
    }

    public function revoke(Request $request, Inquiry $inquiry, InquiryQuote $quote)
    {
        $this->ensureOperator($request);

        if ($quote->inquiry_id !== $inquiry->id) {
            abort(404);
        }
        if ($inquiry->accepted_quote_id !== null) {
            abort(409, 'Cannot revoke an accepted quote');
        }

        $this->quotes->markRejected($quote); // re-use markRejected; or add markRevoked if you prefer

        if ($inquiry->status->value === 'quoted') {
            $this->stateMachine->transition(
                $inquiry,
                TransitionEvent::RevokeQuote,
                actorUserId: $request->user()->id,
                payload: ['quote_version' => $quote->version],
            );
        }

        return new InquiryQuoteResource($quote->fresh());
    }

    private function ensureOperator(Request $request): void
    {
        if (! $request->user()?->can(config('inquiry.permissions.manage', 'inquiry.manage'))) {
            abort(403);
        }
    }
}
