<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Support;

/**
 * 본인확인 식별자(CI/DI) 동일인 검색용 keyed-hash helper.
 *
 * CI 는 전 기관 공통 고정 식별자라, 키 없는 결정적 SHA256 으로 저장하면 DB 단독 유출만으로
 * 특정인의 해시를 직접 계산해 가입 여부를 조회하거나(targeted lookup), 타 유출 DB 와 동일
 * 해시로 동일인을 교차결합(서비스 횡단 추적)할 수 있다.
 *
 * APP_KEY 를 비밀키로 한 HMAC-SHA256 으로 DB 단독 유출 시 해시 재계산을 차단한다.
 * 키 소스를 APP_KEY 로 둔 것은 코어 MailIdentityProvider / 타 본인확인 플러그인과 동일한
 * G7 keyed-hash 표준을 따르기 위함이다.
 *
 * 모든 CI/DI 해시 지점(record 저장 / 로그 metadata / 중복 판정 / verify 반환 identityHash)은
 * 반드시 본 helper 를 단일 진입점으로 사용한다. prefix 는 붙이지 않는다.
 *
 * @since 1.0.0
 */
class KcpIdentityHasher
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

    /**
     * KCP 거래키(reg_cert_key) 의 조회용 인덱스 해시를 반환한다.
     *
     * 콜백에서 수신한 평문 거래키로 거래 행을 역조회하기 위한 값이다. 평문 인덱스를 두면
     * DB 유출 시 거래키가 그대로 노출되므로 해시 인덱스만 저장한다.
     *
     * @param  string  $regCertKey  KCP 거래등록 응답의 거래키
     * @return string 64자리 hex HMAC-SHA256 다이제스트
     */
    public static function hashRegCertKey(string $regCertKey): string
    {
        return self::hash($regCertKey);
    }
}
