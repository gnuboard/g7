<?php

namespace App\Contracts\Extension;

/**
 * 인증 코드를 「보내는」 수단인지 스스로 밝히는 IDV 프로바이더의 보조 계약.
 *
 * 메일·문자처럼 코드를 보내는 수단은 재발송이 의미가 있지만, 인증 앱(TOTP)처럼 사용자의 기기가 코드를 만드는
 * 수단은 보낼 것이 없다. 그런 수단에 재발송을 허용하면 새 challenge 가 시도 상한을 0 으로 되돌려 2단계 코드를
 * 무한히 맞혀 볼 수 있다(KVE-2026-3195). 코어는 이 계약이 false 를 돌려주는 수단의 로그인 2단계 재발송을 거부한다.
 *
 * 이 계약을 구현하지 않은 프로바이더는 코드를 보내는 수단으로 간주한다 — 기존 동작 불변. 구버전 플러그인 호환을 위해
 * challenge 의 채널이 `app` 이면 계약 구현 여부와 무관하게 「보내지 않음」으로 본다.
 *
 * @since 7.1.6
 */
interface ChallengeDeliveryAwareProviderInterface
{
    /**
     * 이 수단이 인증 코드를 사용자에게 보내는지 여부.
     *
     * @return bool 보내면 true (재발송 허용), 기기가 만드는 수단이면 false (재발송 거부)
     */
    public function deliversChallengeCode(): bool;
}
