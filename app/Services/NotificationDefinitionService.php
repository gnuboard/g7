<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use App\Extension\HookManager;
use App\Models\NotificationDefinition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationDefinitionService
{
    /**
     * 캐시 키 접두사 (드라이버 접두사 `g7:core:` 다음에 붙음).
     *
     * @var string
     */
    protected string $cachePrefix = 'notification.definition.';

    /**
     * 캐시 TTL (초) — g7_core_settings('cache.notification_ttl') 추종.
     */
    protected function getCacheTtl(): int
    {
        $value = g7_core_settings('cache.notification_ttl', 3600);

        return $value !== null ? (int) $value : 3600;
    }

    /**
     * @param NotificationDefinitionRepositoryInterface $repository
     * @param CacheInterface $cache
     */
    public function __construct(
        private readonly NotificationDefinitionRepositoryInterface $repository,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 타입으로 알림 정의 조회 (캐싱).
     *
     * @param string $type
     * @return NotificationDefinition|null
     */
    public function resolve(string $type): ?NotificationDefinition
    {
        return $this->cache->remember(
            $this->getCacheKey($type),
            fn () => $this->repository->getActiveByType($type),
            $this->getCacheTtl(),
            ['notification']
        );
    }

    /**
     * 모든 활성 알림 정의 조회 (캐싱).
     *
     * @return Collection
     */
    public function getAllActive(): Collection
    {
        return $this->cache->remember(
            $this->cachePrefix . 'all_active',
            fn () => $this->repository->getAllActive(),
            $this->getCacheTtl(),
            ['notification']
        );
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
        return $this->repository->getByExtension($extensionType, $extensionIdentifier);
    }

    /**
     * 알림 정의 수정.
     *
     * @param NotificationDefinition $definition
     * @param array $data
     * @param int|null $userId
     * @return NotificationDefinition
     */
    public function updateDefinition(NotificationDefinition $definition, array $data, ?int $userId = null): NotificationDefinition
    {
        HookManager::doAction('core.notification_definition.before_update', $definition, $data);

        $data = HookManager::applyFilters(
            'core.notification_definition.filter_update_data',
            $data,
            $definition
        );

        $updated = $this->repository->update($definition, $data);

        $this->invalidateCache($definition->type);

        HookManager::doAction('core.notification_definition.after_update', $updated, $data);

        return $updated;
    }

    /**
     * 활성/비활성 토글.
     *
     * @param NotificationDefinition $definition
     * @return NotificationDefinition
     */
    public function toggleActive(NotificationDefinition $definition): NotificationDefinition
    {
        HookManager::doAction('core.notification_definition.before_toggle_active', $definition);

        $updated = $this->repository->update($definition, [
            'is_active' => ! $definition->is_active,
        ]);

        $this->invalidateCache($definition->type);

        HookManager::doAction('core.notification_definition.after_toggle_active', $updated);

        return $updated;
    }

    /**
     * 정의를 기본 상태로 마킹합니다 (모든 템플릿 리셋 후 호출).
     *
     * @param NotificationDefinition $definition
     * @return NotificationDefinition
     */
    public function markAsDefault(NotificationDefinition $definition): NotificationDefinition
    {
        if ($definition->is_default) {
            return $definition;
        }

        $updated = $this->repository->update($definition, ['is_default' => true]);

        $this->invalidateCache($definition->type);

        return $updated;
    }

    /**
     * 페이지네이션 목록 조회.
     *
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getDefinitions(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->getPaginated($filters, $perPage);
    }

    /**
     * 특정 타입의 캐시 무효화.
     *
     * @param string $type
     * @return void
     */
    public function invalidateCache(string $type): void
    {
        $this->cache->forget($this->getCacheKey($type));
        $this->cache->forget($this->cachePrefix . 'all_active');
    }

    /**
     * 전체 캐시 무효화.
     *
     * @return void
     */
    public function invalidateAllCache(): void
    {
        $this->cache->flushTags(['notification']);
    }

    /**
     * 캐시 키 생성.
     *
     * @param string $type
     * @return string
     */
    private function getCacheKey(string $type): string
    {
        return $this->cachePrefix . $type;
    }
}
