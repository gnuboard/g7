<?php

namespace Plugins\Sirsoft\Gdpr\Repositories;

use Illuminate\Support\Collection;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsent;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprUserConsentRepositoryInterface;

/**
 * GDPR 사용자 현재 동의 상태 Repository 구현체
 */
class GdprUserConsentRepository implements GdprUserConsentRepositoryInterface
{
    /**
     * 사용자 ID와 동의 키로 동의 상태를 조회합니다.
     *
     * @param int $userId 사용자 ID
     * @param string $consentKey 동의 항목 키
     * @return GdprUserConsent|null
     */
    public function findByUserAndKey(int $userId, string $consentKey): ?GdprUserConsent
    {
        return GdprUserConsent::where('user_id', $userId)
            ->where('consent_key', $consentKey)
            ->first();
    }

    /**
     * 사용자 ID로 모든 동의 상태를 조회합니다.
     *
     * @param int $userId 사용자 ID
     * @return Collection<int, GdprUserConsent>
     */
    public function getAllByUserId(int $userId): Collection
    {
        return GdprUserConsent::where('user_id', $userId)->get();
    }

    /**
     * 사용자 ID로 활성 동의(is_consented=true)만 조회합니다.
     *
     * @param int $userId 사용자 ID
     * @return Collection<int, GdprUserConsent>
     */
    public function getActiveByUserId(int $userId): Collection
    {
        return GdprUserConsent::where('user_id', $userId)
            ->where('is_consented', true)
            ->get();
    }

    /**
     * 가상의 비활성 동의 상태 모델을 합성합니다 (DB 미저장).
     *
     * @param int $userId 사용자 ID
     * @param string $consentKey 동의 항목 키 (cookie_ 접두사 포함)
     * @param string|null $consentCategory 카테고리
     * @return GdprUserConsent 합성된 비활성 모델 (DB 미저장)
     */
    public function buildVirtualStatus(int $userId, string $consentKey, ?string $consentCategory = null): GdprUserConsent
    {
        $row = new GdprUserConsent();
        $row->user_id = $userId;
        $row->consent_key = $consentKey;
        $row->consent_category = $consentCategory;
        $row->is_consented = false;
        $row->consent_count = 0;
        $row->consented_at = null;
        $row->revoked_at = null;
        $row->policy_version = null;
        $row->last_source = null;

        // exists=false 로 두어 호출 측이 이 모델이 영구화되지 않았음을 인식 가능 (Eloquent 기본값)
        return $row;
    }

    /**
     * 동의 상태 레코드를 생성하거나 업데이트합니다.
     *
     * @param int $userId 사용자 ID
     * @param string $consentKey 동의 항목 키
     * @param array $data 업데이트 데이터
     * @return GdprUserConsent
     */
    public function upsert(int $userId, string $consentKey, array $data): GdprUserConsent
    {
        $consent = GdprUserConsent::firstOrNew([
            'user_id' => $userId,
            'consent_key' => $consentKey,
        ]);

        $consent->fill($data)->save();

        return $consent->fresh();
    }

    /**
     * 사용자의 모든 활성 동의를 일괄 철회 처리합니다 (탈퇴 시 사용).
     *
     * @param int $userId 사용자 ID
     * @return int 영향받은 행 수
     */
    public function revokeAllForUser(int $userId): int
    {
        return GdprUserConsent::where('user_id', $userId)
            ->where('is_consented', true)
            ->update([
                'is_consented' => false,
                'revoked_at' => now(),
                'last_source' => 'withdraw',
            ]);
    }

    /**
     * 특정 동의 키에 동의한 사용자 수를 반환합니다.
     *
     * @param string $consentKey 동의 항목 키
     * @return int
     */
    public function countConsentedByKey(string $consentKey): int
    {
        return GdprUserConsent::where('consent_key', $consentKey)
            ->where('is_consented', true)
            ->count();
    }

    /**
     * 사용자 ID로 모든 동의 상태 레코드를 삭제합니다.
     *
     * @param int $userId 사용자 ID
     * @return void
     */
    public function deleteByUserId(int $userId): void
    {
        GdprUserConsent::where('user_id', $userId)->delete();
    }
}
