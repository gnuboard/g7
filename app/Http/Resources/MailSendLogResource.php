<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * 메일 발송 이력 API 리소스
 */
class MailSendLogResource extends BaseApiResource
{
    /**
     * 권한 매핑을 반환합니다.
     *
     * @return array<string, string> 능력명 => 권한 식별자 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_delete' => 'core.mail-send-logs.delete',
        ];
    }

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param Request $request HTTP 요청 객체
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sender_email' => $this->sender_email,
            'sender_name' => $this->sender_name,
            'recipient_email' => $this->recipient_email,
            'recipient_name' => $this->recipient_name,
            'subject' => $this->subject,
            'body' => $this->body,
            'template_type' => $this->template_type,
            'extension_type' => $this->extension_type?->value,
            'extension_identifier' => $this->extension_identifier,
            'source' => $this->source,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'sent_at' => $this->formatDateTimeStringForUser($this->sent_at),
            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }
}
