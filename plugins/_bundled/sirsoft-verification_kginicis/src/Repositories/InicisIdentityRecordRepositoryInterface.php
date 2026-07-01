<?php

namespace Plugins\Sirsoft\VerificationKginicis\Repositories;

use Plugins\Sirsoft\VerificationKginicis\Models\InicisIdentityRecord;

/**
 * KG이니시스 본인확인 PII 레코드 Repository 인터페이스.
 *
 * Service / Listener / Controller 는 이 인터페이스만 타입힌트하고 구체 클래스에 의존하지 않는다.
 *
 * @since 1.0.0-beta.1
 */
interface InicisIdentityRecordRepositoryInterface
{
    /**
     * user_id 로 레코드를 조회한다.
     *
     * @param  int  $userId  사용자 ID
     * @return InicisIdentityRecord|null
     */
    public function findByUserId(int $userId): ?InicisIdentityRecord;

    /**
     * mTxId 로 레코드를 조회한다 (감사용 — 일반 callback 매칭은 IdentityVerificationLog.inicis_mtxid 컬럼 사용).
     *
     * @param  string  $mTxId  이니시스 가맹점 거래 ID
     * @return InicisIdentityRecord|null
     */
    public function findByMTxId(string $mTxId): ?InicisIdentityRecord;

    /**
     * di_hash 로 동일인 검색.
     *
     * @param  string  $diHash  SHA256(userDi)
     * @return InicisIdentityRecord|null
     */
    public function findByDiHash(string $diHash): ?InicisIdentityRecord;

    /**
     * ci_hash 로 동일인 검색 (ci2_hash 도 fallback 검색).
     *
     * @param  string  $ciHash  SHA256(userCi)
     * @return InicisIdentityRecord|null
     */
    public function findByCiHash(string $ciHash): ?InicisIdentityRecord;

    /**
     * 사용자 PII 레코드를 생성하거나 갱신한다 (UPSERT).
     *
     * @param  int  $userId  사용자 ID
     * @param  array<string, mixed>  $attributes  컬럼 값 (PII 평문은 호출자가 Crypt::encrypt 처리 후 전달)
     * @return InicisIdentityRecord 생성/갱신된 레코드
     */
    public function upsertForUser(int $userId, array $attributes): InicisIdentityRecord;

    /**
     * user_id 로 레코드를 삭제한다 (회원 탈퇴/사용자 삭제 시).
     *
     * @param  int  $userId  사용자 ID
     * @return bool 삭제 성공 여부 (행이 없어도 true)
     */
    public function deleteByUserId(int $userId): bool;
}
