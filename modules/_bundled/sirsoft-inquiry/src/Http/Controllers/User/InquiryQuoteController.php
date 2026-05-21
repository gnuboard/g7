<?php

namespace Modules\Sirsoft\Inquiry\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryQuote;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryQuoteRepositoryInterface;
use Modules\Sirsoft\Inquiry\Services\InquiryPaymentBridge;
use Modules\Sirsoft\Inquiry\Services\InquiryStateMachine;

class InquiryQuoteController extends Controller
{
    public function __construct(
        private readonly InquiryQuoteRepositoryInterface $quotes,
        private readonly InquiryStateMachine $stateMachine,
        private readonly InquiryPaymentBridge $paymentBridge,
    ) {}

    public function accept(Request $request, Inquiry $inquiry, InquiryQuote $quote)
    {
        $this->authorize('acceptQuote', $inquiry);

        if ($quote->inquiry_id !== $inquiry->id) {
            abort(404);
        }
        if ($quote->status->value !== 'issued') {
            abort(409, 'Quote is not currently active');
        }

        $result = $this->paymentBridge->initiate($inquiry, $quote, $request->user());

        // initiate() returns:
        //   ['redirect_url' => '...']  when ecommerce is wired
        //   ['message' => 'Payment module not installed. Contact operator.'] otherwise
        return response()->json(['data' => $result]);
    }

    public function reject(Request $request, Inquiry $inquiry, InquiryQuote $quote)
    {
        $this->authorize('rejectQuote', $inquiry);

        if ($quote->inquiry_id !== $inquiry->id) {
            abort(404);
        }

        $this->quotes->markRejected($quote);

        $this->stateMachine->transition(
            $inquiry,
            TransitionEvent::RejectQuote,
            actorUserId: $request->user()->id,
            payload: ['quote_version' => $quote->version],
        );

        return response()->json(['data' => ['status' => 'rejected']]);
    }
}
