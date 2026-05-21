<?php

namespace Tests\Feature\Modules\Inquiry;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Sirsoft\Inquiry\Enums\InquiryStatus;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Modules\Sirsoft\Inquiry\Events\InquiryStatusTransitioned;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Services\InquiryStateMachine;
use Tests\TestCase;

class StateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    private function makeInquiry(array $overrides = []): Inquiry
    {
        $user = User::factory()->create();
        return Inquiry::create(array_merge([
            'uuid' => (string) \Str::uuid(),
            'user_id' => $user->id,
            'title' => 'X', 'content' => 'Y',
            'status' => 'received',
        ], $overrides));
    }

    public function test_issue_quote_transitions_received_to_quoted_and_emits_system_message(): void
    {
        Event::fake([InquiryStatusTransitioned::class]);
        $sm = app(InquiryStateMachine::class);
        $inquiry = $this->makeInquiry();

        $sm->transition($inquiry, TransitionEvent::IssueQuote, actorUserId: 1, payload: ['quote_version' => 1, 'quote_total' => 1000000]);

        $inquiry->refresh();
        $this->assertSame(InquiryStatus::Quoted, $inquiry->status);
        $this->assertNotNull($inquiry->quoted_at);

        $sys = $inquiry->messages()->where('sender_role', 'system')->first();
        $this->assertNotNull($sys);
        $this->assertSame('inquiry::system.message.quote_issued', $sys->meta['key']);

        Event::assertDispatched(InquiryStatusTransitioned::class, fn ($e) =>
            $e->from === InquiryStatus::Received && $e->to === InquiryStatus::Quoted
        );
    }

    public function test_revoke_quote_back_to_received(): void
    {
        $sm = app(InquiryStateMachine::class);
        $inquiry = $this->makeInquiry(['status' => 'quoted', 'quoted_at' => now()]);
        $sm->transition($inquiry, TransitionEvent::RevokeQuote, 1, ['quote_version' => 1]);
        $this->assertSame(InquiryStatus::Received, $inquiry->refresh()->status);
    }

    public function test_reject_quote_back_to_received(): void
    {
        $sm = app(InquiryStateMachine::class);
        $inquiry = $this->makeInquiry(['status' => 'quoted', 'quoted_at' => now()]);
        $sm->transition($inquiry, TransitionEvent::RejectQuote, 1, ['quote_version' => 1]);
        $this->assertSame(InquiryStatus::Received, $inquiry->refresh()->status);
    }

    public function test_accept_and_pay_to_in_progress(): void
    {
        $sm = app(InquiryStateMachine::class);
        $inquiry = $this->makeInquiry(['status' => 'quoted', 'quoted_at' => now()]);
        $sm->transition($inquiry, TransitionEvent::AcceptAndPay, null, ['order_uuid' => 'order-xyz']);
        $this->assertSame(InquiryStatus::InProgress, $inquiry->refresh()->status);
        $this->assertNotNull($inquiry->started_at);
    }

    public function test_mark_paid_offline_to_in_progress(): void
    {
        $sm = app(InquiryStateMachine::class);
        $inquiry = $this->makeInquiry(['status' => 'quoted', 'quoted_at' => now()]);
        $sm->transition($inquiry, TransitionEvent::MarkPaidOffline, 1);
        $this->assertSame(InquiryStatus::InProgress, $inquiry->refresh()->status);
    }

    public function test_mark_completed_from_in_progress(): void
    {
        $sm = app(InquiryStateMachine::class);
        $inquiry = $this->makeInquiry(['status' => 'in_progress', 'started_at' => now()]);
        $sm->transition($inquiry, TransitionEvent::MarkCompleted, 1);
        $this->assertSame(InquiryStatus::Completed, $inquiry->refresh()->status);
        $this->assertNotNull($inquiry->completed_at);
    }

    public function test_cancel_from_any_active_state(): void
    {
        $sm = app(InquiryStateMachine::class);
        foreach (['received', 'quoted', 'in_progress'] as $from) {
            $inquiry = $this->makeInquiry(['status' => $from]);
            $sm->transition($inquiry, TransitionEvent::Cancel, 1, ['actor' => 'client']);
            $this->assertSame(InquiryStatus::Canceled, $inquiry->refresh()->status, "from {$from}");
            $this->assertNotNull($inquiry->canceled_at);
        }
    }
}
