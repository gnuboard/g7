<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Repositories;

use Plugins\Sirsoft\VerificationNhnkcp\Models\KcpCertTransaction;

/**
 * `nhnkcp_cert_transactions` 테이블 Repository 계약.
 *
 * @since 1.0.0
 */
interface KcpCertTransactionRepositoryInterface
{
    /**
     * 거래 행을 생성한다 (거래등록 성공 직후).
     *
     * @param  string  $ordrIdxx  가맹점 주문번호
     * @param  string  $regCertKey  KCP 거래키 평문 (구현체가 hash/암호화하여 저장)
     * @param  string  $challengeId  코어 IdentityVerificationLog UUID
     * @return KcpCertTransaction 생성된 거래 행
     */
    public function create(string $ordrIdxx, string $regCertKey, string $challengeId): KcpCertTransaction;

    /**
     * 거래키 평문으로 거래 행을 조회한다 (콜백 역조회).
     *
     * @param  string  $regCertKey  콜백으로 수신한 거래키 평문
     * @return KcpCertTransaction|null 거래 행 또는 null
     */
    public function findByRegCertKey(string $regCertKey): ?KcpCertTransaction;

    /**
     * challenge_id 로 거래 행을 조회한다 (콜백 param_opt 폴백 / 감사).
     *
     * @param  string  $challengeId  코어 IdentityVerificationLog UUID
     * @return KcpCertTransaction|null 거래 행 또는 null
     */
    public function findByChallengeId(string $challengeId): ?KcpCertTransaction;

    /**
     * 거래 행의 거래키 평문을 복호화하여 반환한다.
     *
     * @param  KcpCertTransaction  $transaction  거래 행
     * @return string 거래키 평문 (복호화 실패 시 빈 문자열)
     */
    public function decryptRegCertKey(KcpCertTransaction $transaction): string;

    /**
     * 거래를 소비 처리한다 (결과조회 1회 사용 마킹 — 재사용 차단).
     *
     * @param  int  $transactionId  거래 행 ID
     * @return bool 마킹 성공 여부 (이미 소비된 경우 false)
     */
    public function markConsumed(int $transactionId): bool;
}
