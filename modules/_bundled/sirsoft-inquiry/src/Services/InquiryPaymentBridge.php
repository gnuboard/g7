<?php

namespace Modules\Sirsoft\Inquiry\Services;

use App\Models\User;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryQuote;

class InquiryPaymentBridge
{
    public function initiate(Inquiry $inquiry, InquiryQuote $quote, User $user): array
    {
        // Task 5 will wire ecommerce here.
        return ['message' => 'Payment module not installed. Contact operator for manual confirmation.'];
    }
}
