<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Gdpr\Concerns;

/**
 * 게스트 세션 식별 쿠키(gdpr_session) 발급/검증 트레이트
 *
 * api 미들웨어 그룹에는 EncryptCookies 가 등록되지 않아 쿠키가 평문으로 오간다.
 * 서버가 발급하지 않은 값을 그대로 신뢰하면 임의로 조작한 session_id 로 타인의
 * 게스트 동의 이력을 조회/철회할 수 있으므로, HMAC 서명을 붙여 위조를 차단한다.
 *
 * sirsoft-pay_nicepayments 등 결제 플러그인의 IssuesReceiptCookie 트레이트와
 * 동일한 서명 컨벤션(hash_hmac + config('app.key'))을 사용하며, 만료시각도 서명에
 * 포함해(주문 영수증 쿠키와 동일 구조) 값이 유출되어도 영구히 재사용되지 않게 한다.
 * 쿠키 자체의 브라우저 만료(1년)와 동일하게 맞춘다 — 이 쿠키는 결제 정보가 아니라
 * 쿠키 선호도 식별자라 짧게 잘라 잦은 재발급을 강제할 실익이 없다.
 */
trait IssuesGuestSessionCookie
{
    /**
     * 서명 만료 기간(초). 쿠키 자체의 브라우저 만료(1년, 60*24*365분)와 동일하게 맞춘다.
     */
    private const SIGNATURE_TTL_SECONDS = 60 * 60 * 24 * 365;

    /**
     * 쿠키 값에 서명을 붙입니다.
     *
     * @param  string  $sessionId  UUID v4 게스트 세션 식별자
     * @return string  "{sessionId}|{expiresTs}|{signature}" 형태의 쿠키 값
     */
    protected function signGuestSessionId(string $sessionId): string
    {
        $expiresTs = time() + self::SIGNATURE_TTL_SECONDS;

        return $sessionId.'|'.$expiresTs.'|'.$this->computeGuestSessionSignature($sessionId, $expiresTs);
    }

    /**
     * 쿠키 값에서 서명을 검증하고 원본 session_id 를 반환합니다.
     *
     * 서명이 없거나 위조된 값, 만료된 값은 신뢰하지 않고 null 을 반환합니다
     * (호출자는 미식별 게스트로 취급해야 함).
     *
     * @param  string|null  $cookieValue  요청 쿠키 원본 값
     * @return string|null  검증된 session_id, 위조/형식 오류/만료 시 null
     */
    protected function verifyGuestSessionId(?string $cookieValue): ?string
    {
        if (! is_string($cookieValue) || $cookieValue === '') {
            return null;
        }

        $parts = explode('|', $cookieValue, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$sessionId, $expiresTs, $signature] = $parts;

        if ($sessionId === '' || ! ctype_digit($expiresTs) || ! ctype_xdigit($signature) || strlen($signature) !== 64) {
            return null;
        }

        if ((int) $expiresTs < time()) {
            return null;
        }

        if (! hash_equals($this->computeGuestSessionSignature($sessionId, (int) $expiresTs), $signature)) {
            return null;
        }

        return substr($sessionId, 0, 100);
    }

    /**
     * session_id + 만료시각에 대한 HMAC-SHA256 서명을 계산합니다.
     *
     * @param  string  $sessionId  게스트 세션 식별자
     * @param  int  $expiresTs  서명 만료 시각(unix timestamp)
     * @return string  64자 hex 서명
     */
    private function computeGuestSessionSignature(string $sessionId, int $expiresTs): string
    {
        return hash_hmac('sha256', $sessionId.'|'.$expiresTs, (string) config('app.key', ''));
    }
}
