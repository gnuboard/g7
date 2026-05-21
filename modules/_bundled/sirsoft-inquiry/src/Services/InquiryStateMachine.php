<?php

namespace Modules\Sirsoft\Inquiry\Services;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Inquiry\Enums\InquiryStatus;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Modules\Sirsoft\Inquiry\Events\InquiryStatusTransitioned;
use Modules\Sirsoft\Inquiry\Exceptions\InvalidStateTransitionException;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryMessageRepositoryInterface;

class InquiryStateMachine
{
    /** @var array<string, array{from: array<int, InquiryStatus>, to: InquiryStatus, systemKey: string, timestampColumn: ?string}> */
    private array $rules;

    public function __construct(
        private readonly InquiryMessageRepositoryInterface $messages,
    ) {
        $this->rules = [
            TransitionEvent::IssueQuote->value => [
                'from' => [InquiryStatus::Received],
                'to' => InquiryStatus::Quoted,
                'systemKey' => 'inquiry::system.message.quote_issued',
                'timestampColumn' => 'quoted_at',
            ],
            TransitionEvent::RevokeQuote->value => [
                'from' => [InquiryStatus::Quoted],
                'to' => InquiryStatus::Received,
                'systemKey' => 'inquiry::system.message.quote_revoked',
                'timestampColumn' => null,
            ],
            TransitionEvent::RejectQuote->value => [
                'from' => [InquiryStatus::Quoted],
                'to' => InquiryStatus::Received,
                'systemKey' => 'inquiry::system.message.quote_rejected',
                'timestampColumn' => null,
            ],
            TransitionEvent::AcceptAndPay->value => [
                'from' => [InquiryStatus::Quoted],
                'to' => InquiryStatus::InProgress,
                'systemKey' => 'inquiry::system.message.payment_confirmed',
                'timestampColumn' => 'started_at',
            ],
            TransitionEvent::MarkPaidOffline->value => [
                'from' => [InquiryStatus::Quoted],
                'to' => InquiryStatus::InProgress,
                'systemKey' => 'inquiry::system.message.payment_confirmed_offline',
                'timestampColumn' => 'started_at',
            ],
            TransitionEvent::MarkCompleted->value => [
                'from' => [InquiryStatus::InProgress],
                'to' => InquiryStatus::Completed,
                'systemKey' => 'inquiry::system.message.completed',
                'timestampColumn' => 'completed_at',
            ],
            TransitionEvent::Cancel->value => [
                'from' => [InquiryStatus::Received, InquiryStatus::Quoted, InquiryStatus::InProgress],
                'to' => InquiryStatus::Canceled,
                'systemKey' => 'inquiry::system.message.canceled_by_client', // payload['actor']로 분기는 systemMessageParams에서
                'timestampColumn' => 'canceled_at',
            ],
        ];
    }

    public function transition(Inquiry $inquiry, TransitionEvent $event, ?int $actorUserId = null, array $payload = []): Inquiry
    {
        $rule = $this->rules[$event->value]
            ?? throw new InvalidStateTransitionException($inquiry->status, $event);

        if (! in_array($inquiry->status, $rule['from'], true)) {
            throw new InvalidStateTransitionException($inquiry->status, $event);
        }

        $from = $inquiry->status;
        $to = $rule['to'];

        $systemKey = $rule['systemKey'];
        if ($event === TransitionEvent::Cancel && ($payload['actor'] ?? null) === 'operator') {
            $systemKey = 'inquiry::system.message.canceled_by_operator';
        }

        DB::transaction(function () use ($inquiry, $rule, $to, $payload, $systemKey) {
            $inquiry->status = $to->value;
            if ($rule['timestampColumn']) {
                $inquiry->{$rule['timestampColumn']} = now();
            }
            $inquiry->save();

            $params = $this->systemMessageParams($payload);
            $this->messages->appendSystem($inquiry, $systemKey, $params);
        });

        InquiryStatusTransitioned::dispatch($inquiry, $from, $to, $event, $actorUserId);

        return $inquiry;
    }

    private function systemMessageParams(array $payload): array
    {
        return array_filter([
            'version' => $payload['quote_version'] ?? null,
            'total' => isset($payload['quote_total']) ? number_format($payload['quote_total']) : null,
            'order' => $payload['order_uuid'] ?? null,
            'actor' => $payload['actor'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
