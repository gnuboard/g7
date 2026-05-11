<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\IdentityMessageDefinitionRepositoryInterface;
use App\Contracts\Repositories\IdentityMessageTemplateRepositoryInterface;
use App\Extension\HookManager;
use App\Models\IdentityMessageDefinition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * IDV 메시지 정의 서비스.
 *
 * 알림 시스템(NotificationDefinitionService)과 분리된 IDV 전용 서비스.
 * (provider_id, scope_type, scope_value) 매트릭스를 키로 캐싱합니다.
 */
class IdentityMessageDefinitionService
{
    /**
     * 캐시 키 접두사.
     */
    protected string $cachePrefix = 'identity_message.definition.';

    /**
     * 캐시 태그.
     */
    protected string $cacheTag = 'identity_message';

    /**
     * @param  IdentityMessageDefinitionRepositoryInterface  $repository
     * @param  IdentityMessageTemplateRepositoryInterface  $templateRepository
     * @param  CacheInterface  $cache
     */
    public function __construct(
        private readonly IdentityMessageDefinitionRepositoryInterface $repository,
        private readonly IdentityMessageTemplateRepositoryInterface $templateRepository,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 캐시 TTL (초).
     *
     * @return int
     */
    protected function getCacheTtl(): int
    {
        $value = g7_core_settings('cache.notification_ttl', 3600);

        return $value !== null ? (int) $value : 3600;
    }

    /**
     * (provider, scope_type, scope_value) 활성 정의 조회 (캐싱).
     *
     * @param  string  $providerId
     * @param  string  $scopeType
     * @param  string|null  $scopeValue
     * @return IdentityMessageDefinition|null
     */
    public function resolve(string $providerId, string $scopeType, ?string $scopeValue = null): ?IdentityMessageDefinition
    {
        return $this->cache->remember(
            $this->getCacheKey($providerId, $scopeType, $scopeValue),
            fn () => $this->repository->getActiveByScope($providerId, $scopeType, $scopeValue),
            $this->getCacheTtl(),
            [$this->cacheTag]
        );
    }

    /**
     * 모든 활성 정의 조회 (캐싱).
     *
     * @return Collection
     */
    public function getAllActive(): Collection
    {
        return $this->cache->remember(
            $this->cachePrefix.'all_active',
            fn () => $this->repository->getAllActive(),
            $this->getCacheTtl(),
            [$this->cacheTag]
        );
    }

    /**
     * 특정 확장의 정의 목록 조회.
     *
     * @param  string  $extensionType
     * @param  string  $extensionIdentifier
     * @return Collection
     */
    public function getByExtension(string $extensionType, string $extensionIdentifier): Collection
    {
        return $this->repository->getByExtension($extensionType, $extensionIdentifier);
    }

    /**
     * 정의 수정.
     *
     * @param  IdentityMessageDefinition  $definition
     * @param  array  $data
     * @return IdentityMessageDefinition
     */
    public function updateDefinition(IdentityMessageDefinition $definition, array $data): IdentityMessageDefinition
    {
        HookManager::doAction('core.identity.message_definition.before_update', $definition, $data);

        $data = HookManager::applyFilters(
            'core.identity.message_definition.filter_update_data',
            $data,
            $definition
        );

        $updated = $this->repository->update($definition, $data);

        $this->invalidateAllCache();

        HookManager::doAction('core.identity.message_definition.after_update', $updated, $data);

        return $updated;
    }

    /**
     * 활성/비활성 토글.
     *
     * @param  IdentityMessageDefinition  $definition
     * @return IdentityMessageDefinition
     */
    public function toggleActive(IdentityMessageDefinition $definition): IdentityMessageDefinition
    {
        HookManager::doAction('core.identity.message_definition.before_toggle_active', $definition);

        $updated = $this->repository->update($definition, [
            'is_active' => ! $definition->is_active,
        ]);

        $this->invalidateAllCache();

        HookManager::doAction('core.identity.message_definition.after_toggle_active', $updated);

        return $updated;
    }

    /**
     * 운영자가 정책 매핑 메시지 정의를 신규 생성합니다.
     *
     * extension_type='core', extension_identifier='admin', is_default=false 강제.
     * templates 항목별 자식 행을 같은 트랜잭션에서 생성합니다.
     *
     * @param  array  $data  FormRequest validated payload (provider_id, scope_type, scope_value, name, description, channels, variables, templates[])
     * @return IdentityMessageDefinition
     */
    public function createAdminDefinition(array $data): IdentityMessageDefinition
    {
        HookManager::doAction('core.identity.message_definition.before_create', $data);

        $data = HookManager::applyFilters(
            'core.identity.message_definition.filter_create_data',
            $data,
        );

        $definitionData = [
            'provider_id' => $data['provider_id'],
            'scope_type' => $data['scope_type'],
            'scope_value' => $data['scope_value'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'channels' => $data['channels'],
            'variables' => $data['variables'] ?? [],
            'extension_type' => 'core',
            'extension_identifier' => 'admin',
            'is_active' => true,
            'is_default' => false,
        ];

        $templatesData = $data['templates'] ?? [];

        $definition = DB::transaction(function () use ($definitionData, $templatesData) {
            $definition = $this->repository->store($definitionData);

            foreach ($templatesData as $templateData) {
                $this->templateRepository->create([
                    'definition_id' => $definition->id,
                    'channel' => $templateData['channel'],
                    'subject' => $templateData['subject'],
                    'body' => $templateData['body'],
                    'is_active' => true,
                    'is_default' => false,
                ]);
            }

            return $definition->fresh('templates');
        });

        $this->invalidateAllCache();

        HookManager::doAction('core.identity.message_definition.after_create', $definition);

        return $definition;
    }

    /**
     * 운영자가 추가한 정책 매핑 메시지 정의를 삭제합니다.
     *
     * is_default=true 인 시드 정의는 삭제 거부 (Service 레벨 이중 가드).
     * FK cascadeOnDelete 로 자식 templates 가 자동 정리됩니다.
     *
     * @param  IdentityMessageDefinition  $definition
     * @return bool  삭제 성공 여부
     *
     * @throws RuntimeException  is_default 정의 삭제 시도 시
     */
    public function deleteAdminDefinition(IdentityMessageDefinition $definition): bool
    {
        if ($definition->is_default) {
            throw new RuntimeException('Cannot delete default (seeded) message definition.');
        }

        HookManager::doAction('core.identity.message_definition.before_delete', $definition);

        $deleted = (bool) $definition->delete();

        $this->invalidateAllCache();

        HookManager::doAction('core.identity.message_definition.after_delete', $definition);

        return $deleted;
    }

    /**
     * 정의를 기본 상태로 마킹합니다 (모든 템플릿 리셋 후 호출).
     *
     * @param  IdentityMessageDefinition  $definition
     * @return IdentityMessageDefinition
     */
    public function markAsDefault(IdentityMessageDefinition $definition): IdentityMessageDefinition
    {
        if ($definition->is_default) {
            return $definition;
        }

        $updated = $this->repository->update($definition, ['is_default' => true]);

        $this->invalidateAllCache();

        return $updated;
    }

    /**
     * 페이지네이션 목록 조회.
     *
     * @param  array  $filters
     * @param  int  $perPage
     * @return LengthAwarePaginator
     */
    public function getDefinitions(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->getPaginated($filters, $perPage);
    }

    /**
     * 전체 캐시 무효화 (정의/템플릿 변경 시 자동 호출).
     *
     * @return void
     */
    public function invalidateAllCache(): void
    {
        $this->cache->flushTags([$this->cacheTag]);
    }

    /**
     * 캐시 키 생성.
     *
     * @param  string  $providerId
     * @param  string  $scopeType
     * @param  string|null  $scopeValue
     * @return string
     */
    private function getCacheKey(string $providerId, string $scopeType, ?string $scopeValue): string
    {
        return $this->cachePrefix.$providerId.'.'.$scopeType.'.'.($scopeValue ?? '');
    }
}
