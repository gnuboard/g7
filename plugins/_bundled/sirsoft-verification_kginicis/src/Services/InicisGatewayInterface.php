<?php

namespace Plugins\Sirsoft\VerificationKginicis\Services;

/**
 * KG이니시스 통합인증 외부 통신 게이트웨이 인터페이스.
 *
 * Provider / Listener / Controller 는 이 인터페이스만 의존하고 구체 구현 (HTTP/SEED) 에 의존하지 않는다.
 * 테스트에서는 mock 구현으로 대체 가능.
 *
 * @since 1.0.0-beta.1
 */
interface InicisGatewayInterface
{
    /**
     * 이니시스 콜백의 authRequestUrl 이 표준 도메인 (kssa.inicis.com / fcsa.inicis.com) 인지 검증한다.
     *
     * @param  string  $url  콜백으로 수신한 authRequestUrl
     * @return bool 표준 도메인 여부
     */
    public function validateAuthUrl(string $url): bool;

    /**
     * STEP3 (인증결과 확인 API) 호출.
     *
     * 이니시스 매뉴얼 STEP4 응답 14개 필드를 회수하여 SEED CBC 암호화 필드 (userName/userPhone/
     * userBirthday/userCi/userCi2/userDi) 를 평문으로 복호화한 후 반환한다.
     *
     * @param  string  $authRequestUrl  STEP2 callback 의 authRequestUrl
     * @param  string  $txId  STEP2 callback 의 txId (이니시스 거래 ID)
     * @param  string  $token  STEP2 callback 의 token (SEED 키 — `isUseToken=Y` 인 경우)
     * @return array<string, mixed> 응답 필드 14개 (PII 평문 복호화 상태)
     *
     * @throws \Plugins\Sirsoft\VerificationKginicis\Exceptions\InvalidAuthUrlException 위조 도메인
     * @throws \Plugins\Sirsoft\VerificationKginicis\Exceptions\RemoteCallException 통신 실패
     * @throws \Plugins\Sirsoft\VerificationKginicis\Exceptions\DecryptException SEED 복호화 실패
     */
    public function verifyResult(string $authRequestUrl, string $txId, string $token): array;

    /**
     * 18자 가맹점 거래 ID (mTxId) 를 생성한다.
     *
     * STEP1 인증 페이지 진입 form 에 동봉되며, STEP3 응답에서 다시 회수되어 challenge 매칭 키로 사용된다.
     *
     * @return string 18자 영숫자 문자열
     */
    public function generateMTxId(): string;
}
