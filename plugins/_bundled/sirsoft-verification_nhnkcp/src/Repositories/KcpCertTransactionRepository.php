<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Repositories;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\VerificationNhnkcp\Models\KcpCertTransaction;
use Plugins\Sirsoft\VerificationNhnkcp\Support\KcpIdentityHasher;

/**
 * `nhnkcp_cert_transactions` 테이블 Repository 구현체.
 *
 * 거래키(reg_cert_key) 의 보관 규약을 단일 지점에서 강제한다 —
 * 조회는 keyed-hash 로만, 평문은 Crypt 암호화 컬럼에만.
 *
 * @since 1.0.0
 */
class KcpCertTransactionRepository implements KcpCertTransactionRepositoryInterface
{
    /**
     * 거래 행을 생성한다 (거래등록 성공 직후).
     *
     * @param  string  $ordrIdxx  가맹점 주문번호
     * @param  string  $regCertKey  KCP 거래키 평문
     * @param  string  $challengeId  코어 IdentityVerificationLog UUID
     * @return KcpCertTransaction 생성된 거래 행
     */
    public function create(string $ordrIdxx, string $regCertKey, string $challengeId): KcpCertTransaction
    {
        return KcpCertTransaction::query()->create([
            'ordr_idxx' => $ordrIdxx,
            'reg_cert_key_hash' => KcpIdentityHasher::hashRegCertKey($regCertKey),
            'reg_cert_key_encrypted' => Crypt::encryptString($regCertKey),
            'challenge_id' => $challengeId,
        ]);
    }

    /**
     * 거래키 평문으로 거래 행을 조회한다 (콜백 역조회).
     *
     * @param  string  $regCertKey  콜백으로 수신한 거래키 평문
     * @return KcpCertTransaction|null 거래 행 또는 null
     */
    public function findByRegCertKey(string $regCertKey): ?KcpCertTransaction
    {
        if ($regCertKey === '') {
            return null;
        }

        return KcpCertTransaction::query()
            ->where('reg_cert_key_hash', KcpIdentityHasher::hashRegCertKey($regCertKey))
            ->first();
    }

    /**
     * challenge_id 로 거래 행을 조회한다 (콜백 param_opt 폴백 / 감사).
     *
     * @param  string  $challengeId  코어 IdentityVerificationLog UUID
     * @return KcpCertTransaction|null 거래 행 또는 null
     */
    public function findByChallengeId(string $challengeId): ?KcpCertTransaction
    {
        if ($challengeId === '') {
            return null;
        }

        return KcpCertTransaction::query()
            ->where('challenge_id', $challengeId)
            ->first();
    }

    /**
     * 거래 행의 거래키 평문을 복호화하여 반환한다.
     *
     * @param  KcpCertTransaction  $transaction  거래 행
     * @return string 거래키 평문 (복호화 실패 시 빈 문자열)
     */
    public function decryptRegCertKey(KcpCertTransaction $transaction): string
    {
        $encrypted = (string) $transaction->reg_cert_key_encrypted;

        if ($encrypted === '') {
            return '';
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable $e) {
            // 식별자만 로깅 — 거래키 평문/암호문은 절대 로깅하지 않는다.
            Log::error('KCP 본인확인: 거래키 복호화 실패', [
                'transaction_id' => $transaction->id,
                'challenge_id' => $transaction->challenge_id,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * 거래를 소비 처리한다 (결과조회 1회 사용 마킹 — 재사용 차단).
     *
     * consumed_at IS NULL 조건부 UPDATE 로 원자적으로 마킹한다. 동시에 두 콜백이 도착해도
     * 한쪽만 true 를 받으므로, 호출자는 false 를 replay 로 판정하면 된다.
     *
     * @param  int  $transactionId  거래 행 ID
     * @return bool 마킹 성공 여부 (이미 소비된 경우 false)
     */
    public function markConsumed(int $transactionId): bool
    {
        $affected = KcpCertTransaction::query()
            ->whereKey($transactionId)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        return $affected > 0;
    }
}
