<?php

namespace Modules\Sirsoft\Inquiry\Policies;

use App\Models\User;
use Modules\Sirsoft\Inquiry\Enums\InquiryStatus;
use Modules\Sirsoft\Inquiry\Models\Inquiry;

class InquiryPolicy
{
    public function view(User $user, Inquiry $inquiry): bool
    {
        return $this->isOwner($user, $inquiry) || $this->isOperator($user);
    }

    public function update(User $user, Inquiry $inquiry): bool
    {
        if ($this->isOperator($user)) {
            return true;
        }
        return $this->isOwner($user, $inquiry) && $inquiry->status === InquiryStatus::Received;
    }

    public function cancel(User $user, Inquiry $inquiry): bool
    {
        if ($this->isOperator($user)) {
            return ! in_array($inquiry->status, [InquiryStatus::Completed, InquiryStatus::Canceled], true);
        }
        return $this->isOwner($user, $inquiry)
            && in_array($inquiry->status, [InquiryStatus::Received, InquiryStatus::Quoted], true);
    }

    public function issueQuote(User $user, Inquiry $inquiry): bool
    {
        return $this->isOperator($user);
    }

    public function revokeQuote(User $user, Inquiry $inquiry): bool
    {
        return $this->isOperator($user) && $inquiry->accepted_quote_id === null;
    }

    public function acceptQuote(User $user, Inquiry $inquiry): bool
    {
        return $this->isOwner($user, $inquiry) && $inquiry->status === InquiryStatus::Quoted;
    }

    public function rejectQuote(User $user, Inquiry $inquiry): bool
    {
        return $this->acceptQuote($user, $inquiry);
    }

    public function markPaidOffline(User $user, Inquiry $inquiry): bool
    {
        return $this->isOperator($user);
    }

    public function markCompleted(User $user, Inquiry $inquiry): bool
    {
        return $this->isOperator($user);
    }

    public function postMessage(User $user, Inquiry $inquiry): bool
    {
        return $this->view($user, $inquiry);
    }

    public function viewAttachment(User $user, Inquiry $inquiry): bool
    {
        return $this->view($user, $inquiry);
    }

    public function uploadAttachment(User $user, Inquiry $inquiry): bool
    {
        return $this->view($user, $inquiry);
    }

    private function isOwner(User $user, Inquiry $inquiry): bool
    {
        return $user->id === $inquiry->user_id;
    }

    private function isOperator(User $user): bool
    {
        return $user->can(config('inquiry.permissions.manage', 'inquiry.manage'));
    }
}
