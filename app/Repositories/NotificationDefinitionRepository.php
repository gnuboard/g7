<?php

namespace App\Repositories;

use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use App\Models\NotificationDefinition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationDefinitionRepository implements NotificationDefinitionRepositoryInterface
{
    /**
     * ID로 알림 정의 조회.
     *
     * @param int $id
     * @return NotificationDefinition|null
     */
    public function findById(int $id): ?NotificationDefinition
    {
        return NotificationDefinition::find($id);
    }

    /**
     * 타입으로 알림 정의 조회.
     *
     * @param string $type
     * @return NotificationDefinition|null
     */
    public function findByType(string $type): ?NotificationDefinition
    {
        return NotificationDefinition::byType($type)->first();
    }

    /**
     * 활성 상태인 특정 타입 알림 정의 조회.
     *
     * @param string $type
     * @return NotificationDefinition|null
     */
    public function getActiveByType(string $type): ?NotificationDefinition
    {
        return NotificationDefinition::active()->byType($type)->first();
    }

    /**
     * 모든 활성 알림 정의 조회.
     *
     * @return Collection
     */
    public function getAllActive(): Collection
    {
        return NotificationDefinition::active()->get();
    }

    /**
     * 활성 알림 정의의 로케일별 라벨 맵을 반환합니다.
     *
     * 알림 목록 응답에서 N+1 회피 목적으로 한 번 호출하여 [type => label] 맵을 생성합니다.
     * 라벨 fallback 우선순위: 사용자 로케일 → ko → en → type 식별자
     *
     * @param string|null $locale 사용자 로케일 (null이면 app()->getLocale())
     * @return array<string, string>
     */
    public function getLabelMap(?string $locale = null): array
    {
        $locale = $locale ?? app()->getLocale();

        return NotificationDefinition::active()
            ->get(['type', 'name'])
            ->mapWithKeys(fn (NotificationDefinition $def) => [
                $def->type => $def->getLocalizedName($locale),
            ])
            ->all();
    }

    /**
     * 전체 알림 정의 목록 조회.
     *
     * @return Collection
     */
    public function getAll(): Collection
    {
        return NotificationDefinition::all();
    }

    /**
     * 특정 확장의 알림 정의 목록 조회.
     *
     * @param string $extensionType
     * @param string $extensionIdentifier
     * @return Collection
     */
    public function getByExtension(string $extensionType, string $extensionIdentifier): Collection
    {
        return NotificationDefinition::byExtension($extensionType, $extensionIdentifier)->get();
    }

    /**
     * 알림 정의 수정.
     *
     * @param NotificationDefinition $definition
     * @param array $data
     * @return NotificationDefinition
     */
    public function update(NotificationDefinition $definition, array $data): NotificationDefinition
    {
        $definition->update($data);

        return $definition->fresh();
    }

    /**
     * 페이지네이션 목록 조회.
     *
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = NotificationDefinition::with('templates');

        if (! empty($filters['extension_type'])) {
            $query->where('extension_type', $filters['extension_type']);
        }

        if (! empty($filters['extension_identifier'])) {
            $query->where('extension_identifier', $filters['extension_identifier']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (! empty($filters['channel'])) {
            $query->whereJsonContains('channels', $filters['channel']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $locales = config('app.supported_locales', ['ko', 'en']);

            $query->where(function ($q) use ($search, $locales) {
                $q->where('type', 'like', "%{$search}%");
                foreach ($locales as $locale) {
                    $q->orWhere("name->{$locale}", 'like', "%{$search}%");
                }
            });
        }

        $sortBy = $filters['sort_by'] ?? 'id';
        $sortOrder = $filters['sort_order'] ?? 'asc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($perPage);
    }
}
