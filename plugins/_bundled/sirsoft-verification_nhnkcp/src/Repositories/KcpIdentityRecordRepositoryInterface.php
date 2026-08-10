<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Repositories;

use Plugins\Sirsoft\VerificationNhnkcp\Models\KcpIdentityRecord;

/**
 * `nhnkcp_identity_records` 테이블 Repository 계약.
 *
 * Service / Listener / Controller 는 본 인터페이스만 의존하고 구체 클래스에 의존하지 않는다.
 *
 * @since 1.0.0
 */
interface KcpIdentityRecordRepositoryInterface
{
    /**
     * user_id 로 레코드를 조회한다.
     *
     * @param  int  $userId  사용자 ID
     * @return KcpIdentityRecord|null 레코드 또는 null
     */
    public function findByUserId(int $userId): ?KcpIdentityRecord;

    /**
     * DI keyed-hash 로 동일인을 검색한다.
     *
     * @param  string  $diHash  DI keyed-hash
     * @return KcpIdentityRecord|null 매칭 레코드 또는 null
     */
    public function findByDiHash(string $diHash): ?KcpIdentityRecord;

    /**
     * CI keyed-hash 로 동일인을 검색한다.
     *
     * @param  string  $ciHash  CI keyed-hash
     * @return KcpIdentityRecord|null 매칭 레코드 또는 null
     */
    public function findByCiHash(string $ciHash): ?KcpIdentityRecord;

    /**
     * 지정 사용자를 제외하고 DI/CI keyed-hash 로 동일인을 검색한다.
     *
     * @param  string  $column  검색 컬럼 (di_hash | ci_hash)
     * @param  string  $hash  keyed-hash 값
     * @param  int|null  $exceptUserId  제외할 사용자 ID (본인 재인증 허용)
     * @return KcpIdentityRecord|null 매칭 레코드 또는 null
     */
    public function findByHashExceptUser(string $column, string $hash, ?int $exceptUserId = null): ?KcpIdentityRecord;

    /**
     * 사용자 PII 레코드를 생성하거나 갱신한다 (UPSERT).
     *
     * @param  int  $userId  사용자 ID
     * @param  array<string, mixed>  $attributes  컬럼 값 (PII 평문은 호출자가 암호화 후 전달)
     * @return KcpIdentityRecord 생성/갱신된 레코드
     */
    public function upsertForUser(int $userId, array $attributes): KcpIdentityRecord;

    /**
     * user_id 로 레코드를 삭제한다 (회원 탈퇴 / 사용자 삭제 시).
     *
     * @param  int  $userId  사용자 ID
     * @return bool 삭제 성공 여부 (행이 없어도 true)
     */
    public function deleteByUserId(int $userId): bool;
}
