<?php

namespace Plugins\Sirsoft\Marketing\Repositories;

use Illuminate\Support\Collection;
use Plugins\Sirsoft\Marketing\Repositories\Contracts\MarketingConsentRepositoryInterface;
use Plugins\Sirsoft\Marketing\Models\MarketingConsent;
use Plugins\Sirsoft\Marketing\Models\MarketingConsentHistory;

/**
 * 마케팅 동의 Repository 구현체
 */
class MarketingConsentRepository implements MarketingConsentRepositoryInterface
{
    /**
     * 사용자 ID와 동의 키로 동의 레코드를 조회합니다.
     *
     * @param int $userId 사용자 ID
     * @param string $consentKey 동의 항목 키
     * @return MarketingConsent|null
     */
    public function findByUserAndKey(int $userId, string $consentKey): ?MarketingConsent
    {
        return MarketingConsent::where('user_id', $userId)
            ->where('consent_key', $consentKey)
            ->first();
    }

    /**
     * 사용자 ID로 모든 동의 레코드를 조회합니다.
     *
     * @param int $userId 사용자 ID
     * @return Collection<int, MarketingConsent>
     */
    public function getAllByUserId(int $userId): Collection
    {
        return MarketingConsent::where('user_id', $userId)->get();
    }

    /**
     * 동의 레코드를 생성하거나 업데이트합니다.
     *
     * @param int $userId 사용자 ID
     * @param string $consentKey 동의 항목 키
     * @param array $data 업데이트 데이터
     * @return MarketingConsent
     */
    public function upsert(int $userId, string $consentKey, array $data): MarketingConsent
    {
        $consent = MarketingConsent::firstOrNew([
            'user_id' => $userId,
            'consent_key' => $consentKey,
        ]);

        $consent->fill($data)->save();

        return $consent->fresh();
    }

    /**
     * 특정 채널 키에 동의(is_consented=true)한 사용자 수를 반환합니다.
     *
     * @param string $consentKey 동의 항목 키
     * @return int
     */
    public function countConsentedByKey(string $consentKey): int
    {
        return MarketingConsent::where('consent_key', $consentKey)
            ->where('is_consented', true)
            ->count();
    }

    /**
     * 사용자 ID로 모든 동의 레코드를 삭제합니다.
     *
     * @param int $userId 사용자 ID
     * @return void
     */
    public function deleteByUserId(int $userId): void
    {
        MarketingConsent::where('user_id', $userId)->delete();
    }

    /**
     * 사용자 ID로 동의 이력을 조회합니다.
     *
     * @param int $userId 사용자 ID
     * @return Collection<int, MarketingConsentHistory>
     */
    public function getHistoriesByUserId(int $userId): Collection
    {
        return MarketingConsentHistory::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * 동의 이력 레코드를 생성합니다.
     *
     * @param array $data 이력 데이터
     * @return MarketingConsentHistory
     */
    public function createHistory(array $data): MarketingConsentHistory
    {
        return MarketingConsentHistory::create($data);
    }

    /**
     * 사용자 ID로 모든 동의 이력을 삭제합니다.
     *
     * @param int $userId 사용자 ID
     * @return void
     */
    public function deleteHistoriesByUserId(int $userId): void
    {
        MarketingConsentHistory::where('user_id', $userId)->delete();
    }
}
