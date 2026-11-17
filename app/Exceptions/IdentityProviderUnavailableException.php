<?php

namespace App\Exceptions;

/**
 * 명시로 요청한 본인인증 수단이 등록되지 않았거나 그 목적을 지원하지 않음.
 *
 * 종전에는 `IdentityVerificationManager::resolveForPurpose()` 가 이 경우 조용히 기본 수단(메일)으로 바꿔 발급했다.
 * 정책이 지정한 수단 대신 메일로 발급되면 정책 결박이 무력화되므로 거부한다.
 * 수단을 지정하지 않은 일반 경로는 종전 폴백 체인을 그대로 쓴다.
 *
 * `\InvalidArgumentException` 을 상속해, 해석 실패를 이미 `\Throwable` 로 받아 처리하던 호출자(재요청 대기 계산·
 * 렌더 힌트·가입 후 challenge 발행)의 동작을 바꾸지 않는다.
 *
 * @since 7.1.6
 */
class IdentityProviderUnavailableException extends \InvalidArgumentException
{
    /**
     * @param  string  $providerId  요청한 수단
     * @param  string  $purpose  인증 목적
     */
    public function __construct(
        public readonly string $providerId,
        public readonly string $purpose,
    ) {
        parent::__construct(__('identity.errors.provider_unavailable', ['provider' => $providerId, 'purpose' => $purpose]));
    }
}
