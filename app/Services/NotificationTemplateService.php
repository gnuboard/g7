<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use App\Contracts\Repositories\NotificationTemplateRepositoryInterface;
use App\Extension\HookManager;
use App\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Collection;

class NotificationTemplateService
{
    /**
     * 캐시 키 접두사 (드라이버 접두사 `g7:core:` 다음에 붙음).
     *
     * @var string
     */
    protected string $cachePrefix = 'notification.template.';

    /**
     * 캐시 TTL (초) — g7_core_settings('cache.notification_ttl') 추종.
     */
    protected function getCacheTtl(): int
    {
        return (int) g7_core_settings('cache.notification_ttl', 3600);
    }

    /**
     * @param NotificationTemplateRepositoryInterface $templateRepository
     * @param NotificationDefinitionRepositoryInterface $definitionRepository
     * @param CacheInterface $cache
     */
    public function __construct(
        private readonly NotificationTemplateRepositoryInterface $templateRepository,
        private readonly NotificationDefinitionRepositoryInterface $definitionRepository,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 알림 타입 + 채널로 활성 템플릿 조회 (캐싱).
     *
     * @param string $type
     * @param string $channel
     * @return NotificationTemplate|null
     */
    public function resolve(string $type, string $channel): ?NotificationTemplate
    {
        return $this->cache->remember(
            $this->getCacheKey($type, $channel),
            fn () => $this->templateRepository->getActiveByTypeAndChannel($type, $channel),
            $this->getCacheTtl(),
            ['notification']
        );
    }

    /**
     * 특정 알림 정의의 모든 템플릿 조회.
     *
     * @param int $definitionId
     * @return Collection
     */
    public function getByDefinitionId(int $definitionId): Collection
    {
        return $this->templateRepository->getByDefinitionId($definitionId);
    }

    /**
     * 템플릿 수정.
     *
     * @param NotificationTemplate $template
     * @param array $data
     * @param int|null $userId
     * @return NotificationTemplate
     */
    public function updateTemplate(NotificationTemplate $template, array $data, ?int $userId = null): NotificationTemplate
    {
        $snapshot = $template->toArray();

        HookManager::doAction('core.notification_template.before_update', $template, $data);

        $data = HookManager::applyFilters(
            'core.notification_template.filter_update_data',
            $data,
            $template
        );

        if ($userId) {
            $data['updated_by'] = $userId;
        }

        // 사용자가 편집한 템플릿은 더 이상 기본 상태가 아님
        $data['is_default'] = false;

        $updated = $this->templateRepository->update($template, $data);

        $definition = $updated->definition;
        if ($definition) {
            // 소속 정의도 "커스터마이징됨" 상태로 전환 (리셋 버튼 노출 근거)
            if ($definition->is_default) {
                $this->definitionRepository->update($definition, ['is_default' => false]);
            }

            $this->invalidateCache($definition->type, $updated->channel);
        }

        HookManager::doAction('core.notification_template.after_update', $updated, $data, $snapshot);

        return $updated;
    }

    /**
     * 활성/비활성 토글.
     *
     * @param NotificationTemplate $template
     * @return NotificationTemplate
     */
    public function toggleActive(NotificationTemplate $template): NotificationTemplate
    {
        HookManager::doAction('core.notification_template.before_toggle_active', $template);

        $updated = $this->templateRepository->update($template, [
            'is_active' => ! $template->is_active,
        ]);

        $definition = $updated->definition;
        if ($definition) {
            $this->invalidateCache($definition->type, $updated->channel);
        }

        HookManager::doAction('core.notification_template.after_toggle_active', $updated);

        return $updated;
    }

    /**
     * 기본값으로 복원.
     *
     * @param NotificationTemplate $template
     * @param array $defaultData
     * @return NotificationTemplate
     */
    public function resetToDefault(NotificationTemplate $template, array $defaultData): NotificationTemplate
    {
        $updated = $this->templateRepository->update($template, array_merge($defaultData, [
            'is_default' => true,
            'user_overrides' => null,
        ]));

        $definition = $updated->definition;
        if ($definition) {
            $this->invalidateCache($definition->type, $updated->channel);
        }

        return $updated;
    }

    /**
     * 시더에서 기본 템플릿 데이터를 조회합니다.
     *
     * @param string $type 알림 정의 타입
     * @param string $channel 채널명
     * @return array 기본 템플릿 데이터 (subject, body)
     */
    public function getDefaultTemplateData(string $type, string $channel): array
    {
        $seeder = new \Database\Seeders\NotificationDefinitionSeeder();
        $definitions = $seeder->getDefaultDefinitions();

        // 확장(모듈/플러그인)이 자신의 기본 정의를 추가할 수 있도록 필터 훅 노출
        $definitions = HookManager::applyFilters(
            'core.notification.filter_default_definitions',
            $definitions,
            ['type' => $type, 'channel' => $channel]
        );

        foreach ($definitions as $def) {
            if ($def['type'] === $type) {
                foreach ($def['templates'] as $tpl) {
                    if ($tpl['channel'] === $channel) {
                        return [
                            'subject' => $tpl['subject'] ?? null,
                            'body' => $tpl['body'],
                        ];
                    }
                }
            }
        }

        return [];
    }

    /**
     * 미리보기용 렌더링.
     *
     * definition_id가 제공되면 해당 정의의 변수 메타데이터를 조회하여
     * 샘플 값으로 치환합니다.
     *
     * @param array $data
     * @return array
     */
    public function getPreview(array $data): array
    {
        $locale = $data['locale'] ?? app()->getLocale();
        $subject = $data['subject'][$locale] ?? '';
        $body = $data['body'][$locale] ?? '';

        $replacements = [];

        // definition_id로 변수 메타데이터 조회
        if (isset($data['definition_id'])) {
            $definition = $this->definitionRepository->findById((int) $data['definition_id']);
            if ($definition) {
                foreach ($definition->variables ?? [] as $var) {
                    $key = $var['key'] ?? '';
                    if ($key !== '') {
                        $description = $var['description'] ?? $key;
                        $replacements['{' . $key . '}'] = '[' . $description . ']';
                    }
                }
            }
        }

        // 명시적으로 전달된 variables가 있으면 덮어쓰기
        foreach ($data['variables'] ?? [] as $key => $value) {
            $replacements['{' . $key . '}'] = (string) $value;
        }

        return [
            'subject' => strtr($subject, $replacements),
            'body' => strtr($body, $replacements),
        ];
    }

    /**
     * 특정 타입+채널의 캐시 무효화.
     *
     * @param string $type
     * @param string $channel
     * @return void
     */
    public function invalidateCache(string $type, string $channel): void
    {
        $this->cache->forget($this->getCacheKey($type, $channel));
    }

    /**
     * 캐시 키 생성.
     *
     * @param string $type
     * @param string $channel
     * @return string
     */
    private function getCacheKey(string $type, string $channel): string
    {
        return $this->cachePrefix . $type . '.' . $channel;
    }
}
