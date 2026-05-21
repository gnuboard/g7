<?php

namespace Modules\Sirsoft\Inquiry\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Inquiry\Enums\SenderRole;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryMessage;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryMessageRepositoryInterface;

class InquiryMessageRepository implements InquiryMessageRepositoryInterface
{
    public function append(Inquiry $inquiry, int $senderUserId, SenderRole $role, string $body): InquiryMessage
    {
        return $inquiry->messages()->create([
            'sender_user_id' => $senderUserId,
            'sender_role' => $role->value,
            'body' => $body,
        ]);
    }

    public function appendSystem(Inquiry $inquiry, string $key, array $params = []): InquiryMessage
    {
        return $inquiry->messages()->create([
            'sender_user_id' => null,
            'sender_role' => SenderRole::System->value,
            'body' => null,
            'meta' => ['key' => $key, 'params' => $params],
        ]);
    }

    public function listForInquiry(Inquiry $inquiry, int $perPage = 50): LengthAwarePaginator
    {
        return $inquiry->messages()->orderBy('created_at')->paginate($perPage);
    }

    public function markReadFor(Inquiry $inquiry, SenderRole $oppositeRole): int
    {
        return $inquiry->messages()
            ->where('sender_role', $oppositeRole->value)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
