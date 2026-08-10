<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Services;

use Plugins\Sirsoft\VerificationNhnkcp\Enums\KcpDuplicateField;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityRecordRepositoryInterface;

/**
 * 동일인 중복 가입 판정 Service.
 *
 * 중복 판정은 두 지점에서 필요하다:
 *  ① 인증 완료 시점 (KcpIdentityProvider::verify) — 가입 목적 challenge 를 조기 차단해
 *     사용자가 가입 폼을 다 채운 뒤에야 거절당하는 경험을 막는다.
 *  ② 가입 직전 (AssertNoDuplicateKcpIdentity listener) — 실제 가입을 막는 권위 있는 게이트.
 *
 * 두 지점이 서로 다른 규칙을 갖지 않도록 판정 로직 전체를 본 Service 한 곳에 둔다.
 *
 * @since 1.0.0
 */
class KcpDuplicateIdentityChecker
{
    /**
     * @param  KcpIdentityRecordRepositoryInterface  $recordRepository  본 plugin record Repository
     */
    public function __construct(
        protected readonly KcpIdentityRecordRepositoryInterface $recordRepository,
    ) {}

    /**
     * 설정된 기준 필드의 해시로 동일인이 이미 가입되어 있는지 판정한다.
     *
     * hash 가 비어 있으면(외국인 등 식별값 미발급) 통과시킨다 — 의도된 통과.
     *
     * @param  KcpDuplicateField  $field  운영자가 선택한 판정 기준
     * @param  string|null  $hash  해당 기준의 keyed-hash
     * @param  int|null  $exceptUserId  제외할 사용자 ID (본인 재인증)
     * @return string|null 매칭된 컬럼명 (차단 사유) 또는 null (통과)
     */
    public function findConflictColumn(KcpDuplicateField $field, ?string $hash, ?int $exceptUserId = null): ?string
    {
        if ($hash === null || $hash === '') {
            return null;
        }

        $column = $this->columnFor($field);

        $existing = $this->recordRepository->findByHashExceptUser($column, $hash, $exceptUserId);

        return $existing !== null ? $column : null;
    }

    /**
     * 판정 기준 → record 테이블 컬럼명.
     *
     * @param  KcpDuplicateField  $field  판정 기준
     * @return string 컬럼명
     */
    public function columnFor(KcpDuplicateField $field): string
    {
        return $field === KcpDuplicateField::Di ? 'di_hash' : 'ci_hash';
    }

    /**
     * 매칭된 record 의 사용자 ID 를 반환한다 (감사 로그용 — PII 는 반환하지 않는다).
     *
     * @param  KcpDuplicateField  $field  판정 기준
     * @param  string  $hash  keyed-hash
     * @param  int|null  $exceptUserId  제외할 사용자 ID
     * @return int|null 매칭 사용자 ID 또는 null
     */
    public function findConflictUserId(KcpDuplicateField $field, string $hash, ?int $exceptUserId = null): ?int
    {
        $existing = $this->recordRepository->findByHashExceptUser($this->columnFor($field), $hash, $exceptUserId);

        return $existing !== null ? (int) $existing->user_id : null;
    }
}
