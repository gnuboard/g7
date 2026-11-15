<?php

namespace App\Exceptions;

/**
 * 정책이 지정한 본인인증 수단을 지금 쓸 수 없어 작업을 진행할 수 없음 (HTTP 503).
 *
 * 정책에 수단이 지정되면 그 수단으로 받은 인증만 인정한다. 그 수단의 플러그인이 꺼졌거나 제거되면
 * 다른 수단(메일)으로 대신 발급하지 않고 이 예외로 막는다 — 대신 발급하면 받은 결과가 정책에서 다시 거부되어
 * 사용자가 끝없이 인증을 반복하게 된다. 운영자는 정책의 수단을 바꾸거나 플러그인을 다시 켜야 한다.
 *
 * `IdentityVerificationRequiredException` 과 같은 이유로 `\Error` 를 상속한다 — 확장 컨트롤러의
 * `catch (\Exception)` 에 먹혀 일반 오류로 강등되지 않고 전역 렌더러까지 도달해야 한다.
 *
 * @since 7.1.6
 */
class IdentityPolicyProviderUnavailableException extends \Error
{
    /**
     * @param  string  $policyKey  정책 키
     * @param  string  $providerId  정책이 지정한(쓸 수 없는) 인증 수단
     * @param  string  $message  다국어 메시지 키
     */
    public function __construct(
        public readonly string $policyKey,
        public readonly string $providerId,
        string $message = 'identity.errors.policy_provider_unavailable',
    ) {
        parent::__construct($message);
    }
}
