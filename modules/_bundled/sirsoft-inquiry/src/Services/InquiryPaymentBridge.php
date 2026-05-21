<?php

namespace Modules\Sirsoft\Inquiry\Services;

use App\Models\User;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryQuote;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryQuoteRepositoryInterface;

class InquiryPaymentBridge
{
    public function __construct(
        private readonly InquiryQuoteRepositoryInterface $quotes,
        private readonly InquiryStateMachine $stateMachine,
    ) {}

    /**
     * Returns ['redirect_url' => '...'] when ecommerce module is installed,
     * or ['message' => '...'] otherwise.
     */
    public function initiate(Inquiry $inquiry, InquiryQuote $quote, User $user): array
    {
        $orderClass = '\\Modules\\Sirsoft\\Ecommerce\\Models\\Order';
        if (! class_exists($orderClass)) {
            return ['message' => 'Payment module not installed. Contact operator for manual confirmation.'];
        }

        try {
            // ecommerce Order uses order_status and order_meta fields
            $order = $orderClass::create([
                'user_id' => $user->id,
                'currency' => $quote->currency,
                'total_amount' => (int) $quote->total_amount + (int) $quote->tax_amount,
                'order_status' => 'pending_payment',
                'order_meta' => [
                    'inquiry_id' => $inquiry->id,
                    'quote_id' => $quote->id,
                    'inquiry_uuid' => $inquiry->uuid,
                ],
            ]);

            return [
                'redirect_url' => url('/checkout/' . ($order->uuid ?? $order->id)),
            ];
        } catch (\Throwable) {
            // ecommerce module present but DB not ready (e.g., test environment)
            return ['message' => 'Payment module not installed. Contact operator for manual confirmation.'];
        }
    }

    /**
     * Invoked by HandleOrderPaid listener when ecommerce signals payment completion.
     * The order object may be an Eloquent model or a plain object with same fields.
     */
    public function handleOrderPaid(object $order): void
    {
        // ecommerce Order uses order_meta (array or JSON) and order_status
        $meta = $order->order_meta ?? ($order->meta ?? null);
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        if (! is_array($meta)) {
            $meta = [];
        }

        $inquiryId = $meta['inquiry_id'] ?? null;
        $quoteId = $meta['quote_id'] ?? null;

        if (! $inquiryId || ! $quoteId) {
            return; // Order not tied to an inquiry
        }

        $inquiry = Inquiry::find($inquiryId);
        $quote = InquiryQuote::find($quoteId);
        if (! $inquiry || ! $quote) {
            return;
        }
        if ($inquiry->status->value !== 'quoted') {
            return; // already transitioned
        }

        $this->quotes->markAccepted($quote);
        $inquiry->update([
            'accepted_quote_id' => $quote->id,
            'payment_id' => (string) ($order->uuid ?? $order->id),
        ]);

        $this->stateMachine->transition(
            $inquiry,
            TransitionEvent::AcceptAndPay,
            actorUserId: $inquiry->user_id,
            payload: ['order_uuid' => (string) ($order->uuid ?? $order->id)],
        );
    }
}
