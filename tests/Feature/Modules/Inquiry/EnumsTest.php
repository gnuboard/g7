<?php

namespace Tests\Feature\Modules\Inquiry;

use Modules\Sirsoft\Inquiry\Enums\InquiryStatus;
use Modules\Sirsoft\Inquiry\Enums\QuoteStatus;
use Modules\Sirsoft\Inquiry\Enums\SenderRole;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Tests\TestCase;

class EnumsTest extends TestCase
{
    public function test_inquiry_status_values(): void
    {
        $this->assertSame('received', InquiryStatus::Received->value);
        $this->assertSame('quoted', InquiryStatus::Quoted->value);
        $this->assertSame('in_progress', InquiryStatus::InProgress->value);
        $this->assertSame('completed', InquiryStatus::Completed->value);
        $this->assertSame('canceled', InquiryStatus::Canceled->value);
        $this->assertCount(5, InquiryStatus::cases());
    }

    public function test_quote_status_values(): void
    {
        $this->assertSame('draft', QuoteStatus::Draft->value);
        $this->assertSame('issued', QuoteStatus::Issued->value);
        $this->assertSame('accepted', QuoteStatus::Accepted->value);
        $this->assertSame('rejected', QuoteStatus::Rejected->value);
        $this->assertSame('expired', QuoteStatus::Expired->value);
    }

    public function test_sender_role_values(): void
    {
        $this->assertSame('client', SenderRole::Client->value);
        $this->assertSame('operator', SenderRole::Operator->value);
        $this->assertSame('system', SenderRole::System->value);
    }

    public function test_transition_event_values(): void
    {
        $events = array_map(fn ($e) => $e->value, TransitionEvent::cases());
        $this->assertEqualsCanonicalizing([
            'issue_quote',
            'revoke_quote',
            'reject_quote',
            'accept_and_pay',
            'mark_paid_offline',
            'mark_completed',
            'cancel',
        ], $events);
    }
}
