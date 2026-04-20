<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class NotificationLogResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getValue('id'),
            'channel' => $this->getValue('channel'),
            'notification_type' => $this->getValue('notification_type'),
            'extension_type' => $this->getValue('extension_type'),
            'extension_identifier' => $this->getValue('extension_identifier'),
            'recipient_user_id' => $this->getValue('recipient_user_id'),
            'recipient_identifier' => $this->getValue('recipient_identifier'),
            'recipient_name' => $this->getValue('recipient_name'),
            'sender_user_id' => $this->getValue('sender_user_id'),
            'sender' => $this->whenLoaded('senderUser', function () {
                $user = $this->resource->senderUser;

                return $user ? [
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                ] : null;
            }),
            'recipient' => $this->whenLoaded('recipientUser', function () {
                $user = $this->resource->recipientUser;

                return $user ? [
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                ] : null;
            }),
            'subject' => $this->getValue('subject'),
            'body' => $this->getValue('body'),
            'status' => $this->getValue('status')?->value ?? $this->getValue('status'),
            'error_message' => $this->getValue('error_message'),
            'source' => $this->getValue('source'),
            'sent_at' => $this->formatDateTimeStringForUser($this->resource->sent_at),
            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 리소스별 능력 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_delete' => 'core.notification-logs.delete',
        ];
    }
}
