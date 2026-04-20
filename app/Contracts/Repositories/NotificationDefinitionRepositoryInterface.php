<?php

namespace App\Contracts\Repositories;

use App\Models\NotificationDefinition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface NotificationDefinitionRepositoryInterface
{
    /**
     * ID로 알림 정의 조회.
     *
     * @param int $id
     * @return NotificationDefinition|null
     */
    public function findById(int $id): ?NotificationDefinition;

    /**
     * 타입으로 알림 정의 조회.
     *
     * @param string $type
     * @return NotificationDefinition|null
     */
    public function findByType(string $type): ?NotificationDefinition;

    /**
     * 활성 상태인 특정 타입 알림 정의 조회.
     *
     * @param string $type
     * @return NotificationDefinition|null
     */
    public function getActiveByType(string $type): ?NotificationDefinition;

    /**
     * 모든 활성 알림 정의 조회.
     *
     * @return Collection
     */
    public function getAllActive(): Collection;

    /**
     * 활성 알림 정의의 로케일별 라벨 맵을 반환합니다.
     *
     * 키: type 식별자, 값: 해당 로케일의 다국어 라벨 (fallback: ko → en → 식별자)
     * 알림 목록 응답에서 N+1 회피 목적으로 사용됩니다.
     *
     * @param string|null $locale 사용자 로케일 (null이면 app locale)
     * @return array<string, string>
     */
    public function getLabelMap(?string $locale = null): array;

    /**
     * 전체 알림 정의 목록 조회.
     *
     * @return Collection
     */
    public function getAll(): Collection;

    /**
     * 특정 확장의 알림 정의 목록 조회.
     *
     * @param string $extensionType
     * @param string $extensionIdentifier
     * @return Collection
     */
    public function getByExtension(string $extensionType, string $extensionIdentifier): Collection;

    /**
     * 알림 정의 수정.
     *
     * @param NotificationDefinition $definition
     * @param array $data
     * @return NotificationDefinition
     */
    public function update(NotificationDefinition $definition, array $data): NotificationDefinition;

    /**
     * 페이지네이션 목록 조회.
     *
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator;
}
