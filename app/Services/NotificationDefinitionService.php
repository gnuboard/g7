<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use App\Contracts\Repositories\NotificationTemplateRepositoryInterface;
use App\Exceptions\NotificationDefinitionDeleteForbiddenException;
use App\Extension\HookManager;
use App\Models\NotificationDefinition;
use App\Notifications\Identity\IdentityNotificationType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

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
     *
     * @return int 캐시 TTL (초)
     */
    protected function getCacheTtl(): int
    {
        $value = g7_core_settings('cache.notification_ttl', 3600);

        return $value !== null ? (int) $value : 3600;
    }

    /**
     * @param  NotificationDefinitionRepositoryInterface  $repository  알림 정의 저장소
     * @param  CacheInterface  $cache  캐시
     * @param  NotificationTemplateRepositoryInterface  $templateRepository  알림 템플릿 저장소 (본인인증 정책별 문구 생성용)
     */
    public function __construct(
        private readonly NotificationDefinitionRepositoryInterface $repository,
        private readonly CacheInterface $cache,
        private readonly NotificationTemplateRepositoryInterface $templateRepository,
    ) {}

    /**
     * 타입으로 알림 정의 조회 (캐싱).
     *
     * @param  string  $type
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
            $this->cachePrefix.'all_active',
            fn () => $this->repository->getAllActive(),
            $this->getCacheTtl(),
            ['notification']
        );
    }

    /**
     * 특정 확장의 알림 정의 목록 조회.
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
     * 알림 정의 수정.
     *
     * @param  NotificationDefinition  $definition
     * @param  array  $data
     * @param  int|null  $userId
     * @return NotificationDefinition
     */
    public function updateDefinition(NotificationDefinition $definition, array $data, ?int $userId = null): NotificationDefinition
    {
        $legacyIdentity = $definition->category === IdentityNotificationType::CATEGORY;

        HookManager::doAction('core.notification_definition.before_update', $definition, $data);
        if ($legacyIdentity) {
            // 7.0.x 본인인증 메시지 관리 훅 — 공개 훅은 메이저에서만 지우므로 본인인증 분류에서는 옛 이름도 같은 인자로 함께 발화한다(@deprecated 7.1.0, 8.0 제거).
            HookManager::doAction('core.identity.message_definition.before_update', $definition, $data);
        }

        $data = HookManager::applyFilters(
            'core.notification_definition.filter_update_data',
            $data,
            $definition
        );
        if ($legacyIdentity) {
            $data = HookManager::applyFilters('core.identity.message_definition.filter_update_data', $data, $definition);
        }

        $updated = $this->repository->update($definition, $data);

        $this->invalidateCache($definition->type);

        HookManager::doAction('core.notification_definition.after_update', $updated, $data);
        if ($legacyIdentity) {
            HookManager::doAction('core.identity.message_definition.after_update', $updated, $data);
        }

        return $updated;
    }

    /**
     * 활성/비활성 토글.
     *
     * @param  NotificationDefinition  $definition
     * @return NotificationDefinition
     */
    public function toggleActive(NotificationDefinition $definition): NotificationDefinition
    {
        $legacyIdentity = $definition->category === IdentityNotificationType::CATEGORY;

        HookManager::doAction('core.notification_definition.before_toggle_active', $definition);
        if ($legacyIdentity) {
            // 7.0.x 본인인증 메시지 관리 훅 — 공개 훅은 메이저에서만 지우므로 본인인증 분류에서는 옛 이름도 같은 인자로 함께 발화한다(@deprecated 7.1.0, 8.0 제거).
            HookManager::doAction('core.identity.message_definition.before_toggle_active', $definition);
        }

        $updated = $this->repository->update($definition, [
            'is_active' => ! $definition->is_active,
        ]);

        $this->invalidateCache($definition->type);

        HookManager::doAction('core.notification_definition.after_toggle_active', $updated);
        if ($legacyIdentity) {
            HookManager::doAction('core.identity.message_definition.after_toggle_active', $updated);
        }

        return $updated;
    }

    /**
     * 운영자가 본인인증 정책별 문구(알림 정의)를 추가합니다.
     *
     * 소유는 `core/admin`(코어 시더의 stale 정리 대상이 아니다), type 은 정책 키 규칙
     * (`identity.policy.{key}`, 100자 초과 시 해시), 분류 identity, 메일 채널 고정, 선언 기본값 아님.
     * 정의와 메일 템플릿을 한 트랜잭션에서 만든다.
     *
     * @param  array<string, mixed>  $data  검증된 입력 (policy_key, name, description?, templates.mail.subject/body)
     * @param  int|null  $userId  작성 관리자 ID (템플릿 updated_by)
     * @return NotificationDefinition 생성된 정의 (templates 로드)
     */
    public function createIdentityDefinition(array $data, ?int $userId = null): NotificationDefinition
    {
        HookManager::doAction('core.notification_definition.before_create', $data);
        // 7.0.x 본인인증 메시지 관리 훅 — 공개 훅은 메이저에서만 지우므로 본인인증 분류에서는 옛 이름도 같은 인자로 함께 발화한다(@deprecated 7.1.0, 8.0 제거).
        HookManager::doAction('core.identity.message_definition.before_create', $data);

        $data = HookManager::applyFilters('core.notification_definition.filter_create_data', $data);
        $data = HookManager::applyFilters('core.identity.message_definition.filter_create_data', $data);

        $definitionData = [
            'type' => IdentityNotificationType::forPolicy((string) $data['policy_key']),
            'hook_prefix' => 'core.identity',
            'extension_type' => 'core',
            'extension_identifier' => 'admin',
            'category' => IdentityNotificationType::CATEGORY,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'variables' => IdentityNotificationType::commonVariables(),
            'channels' => ['mail'],
            'hooks' => [],
            'is_active' => true,
            'is_default' => false,
        ];
        $mail = $data['templates']['mail'] ?? [];

        $definition = DB::transaction(function () use ($definitionData, $mail, $userId) {
            $definition = $this->repository->create($definitionData);

            $this->templateRepository->updateOrCreate(
                ['definition_id' => $definition->id, 'channel' => 'mail'],
                [
                    'subject' => $mail['subject'] ?? null,
                    'body' => $mail['body'] ?? [],
                    'is_active' => true,
                    'is_default' => false,
                    'updated_by' => $userId,
                ]
            );

            return $definition->fresh('templates');
        });

        $this->invalidateAllCache();

        HookManager::doAction('core.notification_definition.after_create', $definition, $data);
        HookManager::doAction('core.identity.message_definition.after_create', $definition);

        return $definition;
    }

    /**
     * 운영자가 추가한 본인인증 정책별 문구를 삭제합니다.
     *
     * 선언 정의(코어·확장이 선언한 기본값)와 일반 알림은 지울 수 없다 — 컨트롤러가 403 으로 먼저
     * 거르고, 여기서도 한 번 더 막는다(이중 가드).
     *
     * @param  NotificationDefinition  $definition  삭제할 정의
     * @return bool 삭제 여부
     *
     * @throws NotificationDefinitionDeleteForbiddenException 삭제할 수 없는 정의일 때
     */
    public function deleteIdentityDefinition(NotificationDefinition $definition): bool
    {
        if (! $this->isDeletable($definition)) {
            throw new NotificationDefinitionDeleteForbiddenException;
        }

        HookManager::doAction('core.notification_definition.before_delete', $definition);
        // 7.0.x 본인인증 메시지 관리 훅 — 공개 훅은 메이저에서만 지우므로 본인인증 분류에서는 옛 이름도 같은 인자로 함께 발화한다(@deprecated 7.1.0, 8.0 제거).
        HookManager::doAction('core.identity.message_definition.before_delete', $definition);

        $deleted = $this->repository->delete($definition);

        $this->invalidateAllCache();

        HookManager::doAction('core.notification_definition.after_delete', $definition);
        HookManager::doAction('core.identity.message_definition.after_delete', $definition);

        return $deleted;
    }

    /**
     * 정의를 운영자가 삭제할 수 있는지 판정합니다.
     *
     * @param  NotificationDefinition  $definition  대상 정의
     * @return bool 본인인증 분류이면서 선언 기본값이 아니면 true
     */
    public function isDeletable(NotificationDefinition $definition): bool
    {
        return $definition->category === IdentityNotificationType::CATEGORY
            && $definition->extension_identifier === 'admin'
            && ! $definition->is_default;
    }

    /**
     * 정의를 기본 상태로 마킹합니다 (모든 템플릿 리셋 후 호출).
     *
     * @param  NotificationDefinition  $definition
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
     * @param  array  $filters
     * @param  int  $perPage
     * @return LengthAwarePaginator
     */
    public function getDefinitions(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->getPaginated($filters, $perPage);
    }

    /**
     * 특정 타입의 캐시 무효화.
     *
     * @param  string  $type
     * @return void
     */
    public function invalidateCache(string $type): void
    {
        $this->cache->forget($this->getCacheKey($type));
        $this->cache->forget($this->cachePrefix.'all_active');
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
     * @param  string  $type
     * @return string
     */
    private function getCacheKey(string $type): string
    {
        return $this->cachePrefix.$type;
    }
}
