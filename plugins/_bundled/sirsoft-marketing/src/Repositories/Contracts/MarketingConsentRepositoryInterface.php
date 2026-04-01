<?php

namespace Plugins\Sirsoft\Marketing\Repositories\Contracts;

use Illuminate\Support\Collection;
use Plugins\Sirsoft\Marketing\Models\MarketingConsent;
use Plugins\Sirsoft\Marketing\Models\MarketingConsentHistory;

/**
 * 마케팅 동의 Repository 인터페이스
 */
interface MarketingConsentRepositoryInterface
{
    /**
     * 사용자 ID와 동의 키로 동의 레코드를 조회합니다.
     *
     * @param int $userId 사용자 ID
     * @param string $consentKey 동의 항목 키
     * @return MarketingConsent|null
     */
    public function findByUserAndKey(int $userId, string $consentKey): ?MarketingConsent;

    /**
     * 사용자 ID로 모든 동의 레코드를 조회합니다.
     *
     * @param int $userId 사용자 ID
     * @return Collection<int, MarketingConsent>
     */
    public function getAllByUserId(int $userId): Collection;

    /**
     * 동의 레코드를 생성하거나 업데이트합니다.
     *
     * @param int $userId 사용자 ID
     * @param string $consentKey 동의 항목 키
     * @param array $data 업데이트 데이터
     * @return MarketingConsent
     */
    public function upsert(int $userId, string $consentKey, array $data): MarketingConsent;

    /**
     * 특정 채널 키에 동의(is_consented=true)한 사용자 수를 반환합니다.
     *
     * @param string $consentKey 동의 항목 키
     * @return int
     */
    public function countConsentedByKey(string $consentKey): int;

    /**
     * 사용자 ID로 모든 동의 레코드를 삭제합니다.
     *
     * @param int $userId 사용자 ID
     * @return void
     */
    public function deleteByUserId(int $userId): void;

    /**
     * 사용자 ID로 동의 이력을 조회합니다.
     *
     * @param int $userId 사용자 ID
     * @return Collection<int, MarketingConsentHistory>
     */
    public function getHistoriesByUserId(int $userId): Collection;

    /**
     * 동의 이력 레코드를 생성합니다.
     *
     * @param array $data 이력 데이터
     * @return MarketingConsentHistory
     */
    public function createHistory(array $data): MarketingConsentHistory;

    /**
     * 사용자 ID로 모든 동의 이력을 삭제합니다.
     *
     * @param int $userId 사용자 ID
     * @return void
     */
    public function deleteHistoriesByUserId(int $userId): void;
}
