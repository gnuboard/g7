<?php

namespace App\Extension\IdentityVerification\DTO;

use Carbon\CarbonInterface;

/**
 * 본인인증 Challenge DTO.
 *
 * 프로바이더가 {@see \App\Contracts\Extension\IdentityVerificationInterface::requestChallenge()}
 * 호출 시 반환하는 불변 객체로, 프론트에 노출되는 정보와 서버 내부 참조용 식별자를 함께 담습니다.
 */
final class VerificationChallenge
{
    /**
     * @param  string  $id  challenge UUID (identity_verification_logs.id 와 동일)
     * @param  string  $providerId  프로바이더 식별자
     * @param  string  $purpose  signup|password_reset|self_update|sensitive_action|...
     * @param  string  $channel  email|sms|ipin|...
     * @param  string  $targetHash  SHA256(email|phone) — PII 원본 저장 회피
     * @param  CarbonInterface  $expiresAt  만료 시각
     * @param  string  $renderHint  text_code|link|external_redirect
     * @param  string|null  $redirectUrl  external_redirect 일 때 이동할 외부 URL
     * @param  array  $publicPayload  프론트에 내려줄 공개 페이로드 (민감정보 제외)
     * @param  array  $metadata  서버 내부 참조용 데이터
     */
    public function __construct(
        public readonly string $id,
        public readonly string $providerId,
        public readonly string $purpose,
        public readonly string $channel,
        public readonly string $targetHash,
        public readonly CarbonInterface $expiresAt,
        public readonly string $renderHint,
        public readonly ?string $redirectUrl = null,
        public readonly array $publicPayload = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * 프론트/ResponseHelper 에 그대로 넘길 수 있는 직렬화 배열.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->providerId,
            'purpose' => $this->purpose,
            'channel' => $this->channel,
            'render_hint' => $this->renderHint,
            'redirect_url' => $this->redirectUrl,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'public_payload' => $this->publicPayload,
        ];
    }
}
