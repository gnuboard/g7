<?php

namespace Plugins\Sirsoft\VerificationKginicis\Support;

/**
 * 본인확인 식별자(CI/DI) 동일인 검색용 keyed-hash helper.
 *
 * CI 는 전 기관 공통 고정 식별자라, salt/키 없는 결정적 SHA256 으로 저장하면
 * DB 단독 유출만으로 특정인 해시를 직접 계산해 가입 여부를 조회하거나(targeted lookup),
 * 타 유출 DB 와 동일 해시로 동일인을 교차결합(서비스 횡단 추적)할 수 있다.
 *
 * APP_KEY 를 비밀키로 한 HMAC-SHA256 으로 전환하여 DB 단독 유출 시 해시 재계산을 차단한다.
 * 키 소스를 APP_KEY 로 둔 것은 코어 MailIdentityProvider / 이커머스 GuestOrderAuthService 와
 * 동일한 G7 keyed-hash 표준을 따르기 위함이다.
 *
 * 모든 CI/DI 해시 지점(record 저장 / 로그 metadata / binding 비교 / verify 반환 identityHash)은
 * 반드시 본 helper 를 단일 진입점으로 사용하여 동일 방식으로 해시되도록 한다. prefix 는 붙이지 않는다.
 *
 * @since 1.0.0-beta.1
 */
class InicisIdentityHasher
{
    /**
     * 식별자 값을 APP_KEY 기반 HMAC-SHA256 으로 해시한다.
     *
     * @param  string  $value  해시할 식별자 평문 (CI/DI 등)
     * @return string 64자리 hex HMAC-SHA256 다이제스트
     */
    public static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
