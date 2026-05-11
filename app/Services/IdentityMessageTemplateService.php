<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\IdentityMessageTemplateRepositoryInterface;
use App\Extension\HookManager;
use App\Models\IdentityMessageDefinition;
use App\Models\IdentityMessageTemplate;
use Illuminate\Support\Facades\Auth;

/**
 * IDV 메시지 템플릿 서비스.
 *
 * 알림 시스템(NotificationTemplateService)과 분리된 IDV 전용 서비스.
 * (definition_id, channel) 키로 캐싱하며, 변수 미리보기/기본값 복원 기능을 제공합니다.
 */
class IdentityMessageTemplateService
{
    /**
     * 캐시 키 접두사.
     */
    protected string $cachePrefix = 'identity_message.template.';

    /**
     * 캐시 태그 (DefinitionService 와 공유).
     */
    protected string $cacheTag = 'identity_message';

    /**
     * @param  IdentityMessageTemplateRepositoryInterface  $repository
     * @param  IdentityMessageDefinitionService  $definitionService
     * @param  CacheInterface  $cache
     */
    public function __construct(
        private readonly IdentityMessageTemplateRepositoryInterface $repository,
        private readonly IdentityMessageDefinitionService $definitionService,
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
     * (definition_id, channel) 활성 템플릿 조회 (캐싱).
     *
     * @param  int  $definitionId
     * @param  string  $channel
     * @return IdentityMessageTemplate|null
     */
    public function resolve(int $definitionId, string $channel): ?IdentityMessageTemplate
    {
        return $this->cache->remember(
            $this->getCacheKey($definitionId, $channel),
            fn () => $this->repository->getActiveByDefinitionAndChannel($definitionId, $channel),
            $this->getCacheTtl(),
            [$this->cacheTag]
        );
    }

    /**
     * 템플릿 수정.
     *
     * @param  IdentityMessageTemplate  $template
     * @param  array  $data
     * @return IdentityMessageTemplate
     */
    public function updateTemplate(IdentityMessageTemplate $template, array $data): IdentityMessageTemplate
    {
        HookManager::doAction('core.identity.message_template.before_update', $template, $data);

        $data = HookManager::applyFilters(
            'core.identity.message_template.filter_update_data',
            $data,
            $template
        );

        if (! array_key_exists('updated_by', $data) && Auth::id()) {
            $data['updated_by'] = Auth::id();
        }

        $data['is_default'] = false;

        $updated = $this->repository->update($template, $data);

        if ($updated->definition && $updated->definition->is_default) {
            $this->definitionService->updateDefinition($updated->definition, ['is_default' => false]);
        }

        $this->definitionService->invalidateAllCache();

        HookManager::doAction('core.identity.message_template.after_update', $updated, $data);

        return $updated;
    }

    /**
     * 활성/비활성 토글.
     *
     * @param  IdentityMessageTemplate  $template
     * @return IdentityMessageTemplate
     */
    public function toggleActive(IdentityMessageTemplate $template): IdentityMessageTemplate
    {
        HookManager::doAction('core.identity.message_template.before_toggle_active', $template);

        $updated = $this->repository->update($template, [
            'is_active' => ! $template->is_active,
        ]);

        $this->definitionService->invalidateAllCache();

        HookManager::doAction('core.identity.message_template.after_toggle_active', $updated);

        return $updated;
    }

    /**
     * 템플릿을 시더 기본값으로 복원합니다.
     *
     * @param  IdentityMessageTemplate  $template
     * @return IdentityMessageTemplate
     */
    public function resetToDefault(IdentityMessageTemplate $template): IdentityMessageTemplate
    {
        HookManager::doAction('core.identity.message_template.before_reset', $template);

        $defaultData = $this->getDefaultTemplateData($template);

        if ($defaultData === null) {
            HookManager::doAction('core.identity.message_template.reset_no_default', $template);

            return $template;
        }

        // user_overrides 추적 회피 — reset 은 의도적 복원이므로 trackable 필드 변경을
        // user_overrides 에 다시 추가하면 안 됨. HasUserOverrides 의 시더 플래그 재사용.
        $previousFlag = app()->bound('user_overrides.seeding') ? app('user_overrides.seeding') : null;
        app()->instance('user_overrides.seeding', true);

        try {
            $updated = $this->repository->update($template, [
                'subject' => $defaultData['subject'] ?? null,
                'body' => $defaultData['body'] ?? '',
                'is_active' => $defaultData['is_active'] ?? true,
                'is_default' => true,
                'user_overrides' => [],
            ]);
        } finally {
            if ($previousFlag === null) {
                app()->forgetInstance('user_overrides.seeding');
            } else {
                app()->instance('user_overrides.seeding', $previousFlag);
            }
        }

        $this->definitionService->invalidateAllCache();

        HookManager::doAction('core.identity.message_template.after_reset', $updated);

        return $updated;
    }

    /**
     * 변수 치환 미리보기.
     *
     * @param  IdentityMessageTemplate  $template
     * @param  array  $data
     * @param  string|null  $locale
     * @return array{subject: string, body: string}
     */
    public function getPreview(IdentityMessageTemplate $template, array $data = [], ?string $locale = null): array
    {
        return $template->replaceVariables($data, $locale);
    }

    /**
     * 시더가 정의한 기본 템플릿 데이터를 반환합니다.
     *
     * @param  IdentityMessageTemplate  $template
     * @return array|null
     */
    protected function getDefaultTemplateData(IdentityMessageTemplate $template): ?array
    {
        $definition = $template->definition;

        if (! $definition instanceof IdentityMessageDefinition) {
            return null;
        }

        $allDefaults = $this->collectDefaultDefinitions();

        foreach ($allDefaults as $defaultDefinition) {
            if (! $this->matchesDefinition($defaultDefinition, $definition)) {
                continue;
            }

            foreach ($defaultDefinition['templates'] ?? [] as $defaultTemplate) {
                if (($defaultTemplate['channel'] ?? null) === $template->channel) {
                    return $defaultTemplate;
                }
            }
        }

        return null;
    }

    /**
     * 코어 + 확장의 기본 정의를 모두 수집합니다 (filter 훅 통합).
     *
     * 코어 정의는 `config/core.php` 의 `identity_messages` 블록을 SSoT 로 직독합니다.
     * 확장(모듈/플러그인)은 `core.identity.filter_default_message_definitions` 훅으로 자체 정의를 추가합니다.
     *
     * @return array
     */
    protected function collectDefaultDefinitions(): array
    {
        $coreDefinitions = $this->loadCoreMessageDefinitions();

        return HookManager::applyFilters(
            'core.identity.filter_default_message_definitions',
            $coreDefinitions,
            []
        );
    }

    /**
     * config/core.php 의 identity_messages 블록을 정규화하여 반환합니다.
     *
     * `'variables' => '__common__'` 마커는 commonVariables() 로 expand 하며,
     * extension_type/extension_identifier 를 'core' 로 자동 주입합니다.
     *
     * @return array
     */
    protected function loadCoreMessageDefinitions(): array
    {
        $messages = config('core.identity_messages', []);
        $common = $this->commonVariables();
        $result = [];

        foreach ($messages as $data) {
            if (($data['variables'] ?? null) === '__common__') {
                $data['variables'] = $common;
            }
            $data['extension_type'] = 'core';
            $data['extension_identifier'] = 'core';
            $result[] = $data;
        }

        return $result;
    }

    /**
     * 표준 변수 메타데이터 (모든 mail 정의 공통).
     *
     * config/core.php 의 identity_messages 블록에서 `'variables' => '__common__'` 마커로 참조됩니다.
     *
     * @return array<int, array{key: string, description: string}>
     */
    protected function commonVariables(): array
    {
        return [
            ['key' => 'code', 'description' => '인증 코드 (text_code 흐름)'],
            ['key' => 'action_url', 'description' => '검증 링크 URL (link 흐름)'],
            ['key' => 'expire_minutes', 'description' => '만료까지 남은 분'],
            ['key' => 'purpose_label', 'description' => '인증 목적 라벨 (다국어)'],
            ['key' => 'app_name', 'description' => '사이트명'],
            ['key' => 'site_url', 'description' => '사이트 URL'],
            ['key' => 'recipient_email', 'description' => '수신자 이메일'],
        ];
    }

    /**
     * 시더 데이터가 특정 정의와 매칭되는지 확인합니다.
     *
     * @param  array  $defaultDefinition
     * @param  IdentityMessageDefinition  $definition
     * @return bool
     */
    protected function matchesDefinition(array $defaultDefinition, IdentityMessageDefinition $definition): bool
    {
        return ($defaultDefinition['provider_id'] ?? null) === $definition->provider_id
            && ($defaultDefinition['scope_type'] ?? null) === $definition->scope_type->value
            && ((string) ($defaultDefinition['scope_value'] ?? '')) === (string) $definition->scope_value;
    }

    /**
     * 캐시 키 생성.
     *
     * @param  int  $definitionId
     * @param  string  $channel
     * @return string
     */
    private function getCacheKey(int $definitionId, string $channel): string
    {
        return $this->cachePrefix.$definitionId.'.'.$channel;
    }
}
