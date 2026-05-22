<?php

namespace Modules\Sirsoft\Inquiry\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Modules\Sirsoft\Inquiry\Events\InquiryStatusTransitioned;
use Modules\Sirsoft\Inquiry\Notifications\InquiryCanceled;
use Modules\Sirsoft\Inquiry\Notifications\InquiryCompleted;
use Modules\Sirsoft\Inquiry\Notifications\InquiryReceivedToOperators;
use Modules\Sirsoft\Inquiry\Notifications\PaymentConfirmed;
use Modules\Sirsoft\Inquiry\Notifications\QuoteIssued;
use Modules\Sirsoft\Inquiry\Notifications\QuoteRevoked;

class DispatchInquiryStatusNotifications
{
    public function handle(InquiryStatusTransitioned $event): void
    {
        $inquiry = $event->inquiry;
        $client = $inquiry->user;

        switch ($event->event) {
            case TransitionEvent::IssueQuote:
                $latestQuote = $inquiry->quotes()->latest('version')->first();
                Notification::send([$client], new QuoteIssued($inquiry, [
                    'version' => $latestQuote?->version,
                    'total' => $latestQuote?->total_amount,
                ]));
                break;

            case TransitionEvent::RevokeQuote:
                Notification::send([$client], new QuoteRevoked($inquiry, [
                    'version' => $event->inquiry->quotes()->latest('version')->first()?->version,
                ]));
                break;

            case TransitionEvent::AcceptAndPay:
            case TransitionEvent::MarkPaidOffline:
                $operators = $this->operators();
                Notification::send(array_merge([$client], $operators), new PaymentConfirmed($inquiry));
                break;

            case TransitionEvent::MarkCompleted:
                Notification::send([$client], new InquiryCompleted($inquiry));
                break;

            case TransitionEvent::Cancel:
                $actor = $event->actorUserId;
                $actorRole = ($actor === $client->id) ? 'client' : 'operator';
                $recipients = $actorRole === 'client' ? $this->operators() : [$client];
                Notification::send($recipients, new InquiryCanceled($inquiry, ['actor' => $actorRole]));
                break;
        }

        // 새 의뢰 접수 알림 (status='received' 신규 진입은 InquiryRepository에서 이벤트 dispatch 안 함)
        // → 별도 InquiryCreated 이벤트 또는 InquiryRepository::create() 후 fireevent.
        //   여기서는 다루지 않음 (Task 7에서 ServiceProvider 안에 explicit dispatch 처리).
    }

    /** @return array<User> */
    private function operators(): array
    {
        $permission = config('inquiry.permissions.notify', 'inquiry.notify');
        // Try permission relationship first; fallback to roles relationship.
        return User::whereHas('roles.permissions', function ($q) use ($permission) {
            $q->where('identifier', $permission);
        })->get()->all();
    }
}
