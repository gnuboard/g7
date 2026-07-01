<?php

namespace Plugins\Sirsoft\Gdpr\Repositories\Contracts;

use Illuminate\Support\Collection;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsent;

/**
 * GDPR 사용자 현재 동의 상태 Repository 인터페이스
 */
interface GdprUserConsentRepositoryInterface
{
    /**
     * 사용자 ID와 동의 키로 동의 상태를 조회합니다.
     *
     * @param int $userId 사용자 ID
     * @param string $consentKey 동의 항목 키
     * @return GdprUserConsent|null
     */
    public function findByUserAndKey(int $userId, string $consentKey): ?GdprUserConsent;

    /**
     * 사용자 ID로 모든 동의 상태를 조회합니다.
     *
     * @param int $userId 사용자 ID
     * @return Collection<int, GdprUserConsent>
     */
    public function getAllByUserId(int $userId): Collection;

    /**
     * 사용자 ID로 활성 동의(is_consented=true)만 조회합니다.
     *
     * @param int $userId 사용자 ID
     * @return Collection<int, GdprUserConsent>
     */
    public function getActiveByUserId(int $userId): Collection;

    /**
     * 가상의 비활성 동의 상태 모델을 합성합니다 (DB 미저장).
     *
     * 마이페이지 동의 매트릭스에서 카탈로그에는 있지만 user_consents 에 row 가 없는 항목을
     * "비활성 동의" 로 노출하기 위해 사용. Service 가 Model 을 직접 인스턴스화하지 않도록
     * Repository 에 위임 (service-repository.md 규정 6항: 가상 모델 합성 포함).
     *
     * @param int $userId 사용자 ID
     * @param string $consentKey 동의 항목 키 (cookie_ 접두사 포함)
     * @param string|null $consentCategory 카테고리 (예: necessary/analytics/marketing)
     * @return GdprUserConsent 합성된 비활성 모델 (DB 미저장)
     */
    public function buildVirtualStatus(int $userId, string $consentKey, ?string $consentCategory = null): GdprUserConsent;

    /**
     * 동의 상태 레코드를 생성하거나 업데이트합니다.
     *
     * @param int $userId 사용자 ID
     * @param string $consentKey 동의 항목 키
     * @param array $data 업데이트 데이터
     * @return GdprUserConsent
     */
    public function upsert(int $userId, string $consentKey, array $data): GdprUserConsent;

    /**
     * 사용자의 모든 활성 동의를 일괄 철회 처리합니다 (탈퇴 시 사용).
     *
     * @param int $userId 사용자 ID
     * @return int 영향받은 행 수
     */
    public function revokeAllForUser(int $userId): int;

    /**
     * 특정 동의 키에 동의한 사용자 수를 반환합니다.
     *
     * @param string $consentKey 동의 항목 키
     * @return int
     */
    public function countConsentedByKey(string $consentKey): int;

    /**
     * 사용자 ID로 모든 동의 상태 레코드를 삭제합니다.
     *
     * @param int $userId 사용자 ID
     * @return void
     */
    public function deleteByUserId(int $userId): void;
}
