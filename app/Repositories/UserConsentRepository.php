<?php

namespace App\Repositories;

use App\Contracts\Repositories\UserConsentRepositoryInterface;
use App\Models\UserConsent;
use Illuminate\Database\Eloquent\Collection;

class UserConsentRepository implements UserConsentRepositoryInterface
{
    public function __construct(
        private UserConsent $model
    ) {}

    /**
     * 새로운 동의 이력을 기록합니다.
     *
     * @param  array  $data  동의 데이터
     * @return UserConsent 생성된 동의 이력 모델
     */
    public function record(array $data): UserConsent
    {
        return $this->model->create($data);
    }

    /**
     * 특정 사용자의 모든 동의 이력을 조회합니다 (최신순).
     *
     * @param  int  $userId  사용자 ID
     * @return Collection 동의 이력 컬렉션
     */
    public function getByUser(int $userId): Collection
    {
        return $this->model
            ->where('user_id', $userId)
            ->orderByDesc('agreed_at')
            ->get();
    }

    /**
     * 특정 사용자의 특정 유형 최신 동의 이력을 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $consentType  동의 유형 (ConsentType 값)
     * @return UserConsent|null 최신 동의 이력 또는 null
     */
    public function getLatestByType(int $userId, string $consentType): ?UserConsent
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('consent_type', $consentType)
            ->orderByDesc('agreed_at')
            ->first();
    }
}
