<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * 본인인증 이력(IdentityVerificationLog) API 리소스.
 *
 * 사용자 timezone 변환 + 알림 발송 이력과 동일한 응답 일관성 확보.
 */
class IdentityLogResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getValue('id'),
            'provider_id' => $this->getValue('provider_id'),
            'purpose' => $this->getValue('purpose'),
            'channel' => $this->getValue('channel'),
            'user_id' => $this->getValue('user_id'),
            'target_hash' => $this->getValue('target_hash'),
            'status' => $this->getValue('status'),
            'attempts' => $this->getValue('attempts'),
            'max_attempts' => $this->getValue('max_attempts'),
            'ip_address' => $this->getValue('ip_address'),
            'user_agent' => $this->getValue('user_agent'),
            'origin_type' => $this->getValue('origin_type'),
            'origin_identifier' => $this->getValue('origin_identifier'),
            'origin_policy_key' => $this->getValue('origin_policy_key'),
            'properties' => $this->getValue('properties'),
            'metadata' => $this->getValue('metadata'),
            'created_at' => $this->formatDateTimeStringForUser($this->resource->created_at),
            'verified_at' => $this->formatDateTimeStringForUser($this->resource->verified_at),
            'expires_at' => $this->formatDateTimeStringForUser($this->resource->expires_at),
        ];
    }
}
