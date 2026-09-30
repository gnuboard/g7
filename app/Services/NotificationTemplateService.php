<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use App\Contracts\Repositories\NotificationTemplateRepositoryInterface;
use App\Extension\HookManager;
use App\Models\NotificationTemplate;
use App\Services\LanguagePack\LanguagePackSeedInjector;
use Illuminate\Database\Eloquent\Collection;

class NotificationTemplateService
{
    /**
     * 캐시 키 접두사 (드라이버 접두사 `g7:core:` 다음에 붙음).
     */
    protected string $cachePrefix = 'notification.template.';

    /**
     * 캐시 TTL (초) — g7_core_settings('cache.notification_ttl') 추종.
     *
     * @return int 캐시 TTL (초, 기본 3600)
     */
    protected function getCacheTtl(): int
    {
        return (int) g7_core_settings('cache.notification_ttl', 3600);
    }

    /**
     * @param  NotificationTemplateRepositoryInterface  $templateRepository
     * @param  NotificationDefinitionRepositoryInterface  $definitionRepository
     * @param  CacheInterface  $cache
     * @param  LanguagePackSeedInjector  $seedInjector  기본 정의에 활성 언어팩(코어·확장) seed 로케일을 병합하는 인젝터
     */
    public function __construct(
        private readonly NotificationTemplateRepositoryInterface $templateRepository,
        private readonly NotificationDefinitionRepositoryInterface $definitionRepository,
        private readonly CacheInterface $cache,
        private readonly LanguagePackSeedInjector $seedInjector,
    ) {}

    /**
     * 알림 타입 + 채널로 활성 템플릿 조회 (캐싱).
     *
     * @param  string  $type
     * @param  string  $channel
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
     * @param  int  $definitionId
     * @return Collection
     */
    public function getByDefinitionId(int $definitionId): Collection
    {
        return $this->templateRepository->getByDefinitionId($definitionId);
    }

    /**
     * 템플릿 수정.
     *
     * @param  NotificationTemplate  $template
     * @param  array  $data
     * @param  int|null  $userId
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

        $updated = $this->templateRepository->update($template, $data);

        // 실제로 고친 칸이 있을 때만 「기본」 을 푼다 — 저장만 해도 기본이 풀리면 「기본값 복원」 버튼이 뜨고
        // 새 버전 문구 교정도 막힌다(#727 D5-⑤). 수정 여부는 모델이 기록한 user_overrides 로 판정한다.
        $customized = ! empty($updated->user_overrides);
        if ((bool) $updated->is_default === $customized) {
            $updated = $this->templateRepository->update($updated, ['is_default' => ! $customized]);
        }

        $definition = $updated->definition;
        if ($definition) {
            // 소속 정의도 템플릿 중 하나라도 고쳤으면 "커스터마이징됨" (리셋 버튼 노출 근거)
            if ($customized && $definition->is_default) {
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
     * @param  NotificationTemplate  $template
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
     * @param  NotificationTemplate  $template
     * @param  array  $defaultData
     * @return NotificationTemplate
     */
    public function resetToDefault(NotificationTemplate $template, array $defaultData): NotificationTemplate
    {
        // 복원은 사용자 수정이 아니므로 seeding 컨텍스트로 감싸 HasUserOverrides 의
        // updating 자동 기록을 비활성화한다. 이 가드가 없으면 복원되는 trackable 필드
        // (subject/body/click_url/recipients)가 dirty 로 감지되어 user_overrides 가
        // 즉시 재기록되고, 복원이 무효화된다.
        app()->instance('user_overrides.seeding', true);
        try {
            $updated = $this->templateRepository->update($template, array_merge($defaultData, [
                'is_default' => true,
                'user_overrides' => null,
            ]));
        } finally {
            app()->forgetInstance('user_overrides.seeding');
        }

        $definition = $updated->definition;
        if ($definition) {
            $this->invalidateCache($definition->type, $updated->channel);
        }

        return $updated;
    }

    /**
     * 시더에서 기본 템플릿 데이터를 조회합니다.
     *
     * @param  string  $type  알림 정의 타입
     * @param  string  $channel  채널명
     * @return array 기본 템플릿 데이터 (subject, body)
     */
    public function getDefaultTemplateData(string $type, string $channel): array
    {
        // config/core.php 의 notification_definitions 블록을 SSoT 로 읽어 type/extension_* 정규화
        $coreDefinitions = config('core.notification_definitions', []);
        $definitions = [];
        foreach ($coreDefinitions as $coreType => $data) {
            $definitions[] = array_merge($data, [
                'type' => $coreType,
                'extension_type' => 'core',
                'extension_identifier' => 'core',
            ]);
        }

        // 확장(모듈/플러그인)이 자신의 기본 정의를 추가할 수 있도록 필터 훅 노출
        $definitions = HookManager::applyFilters(
            'core.notification.filter_default_definitions',
            $definitions,
            ['type' => $type, 'channel' => $channel]
        );

        // 활성 언어팩 seed 로케일 병합 — 시딩(seed.notifications.translations)과 같은 SSoT.
        // 이 병합이 없으면 [기본값 복원]이 팩이 주입해 둔 로케일(ja 등)을 config 의
        // ko/en 만으로 대체해 영구 소실시킨다 (2026-08-23 실사례 — #597 보완 실측).
        $definitions = $this->mergeLanguagePackLocalesIntoDefaults($definitions);

        foreach ($definitions as $def) {
            if ($def['type'] === $type) {
                foreach ($def['templates'] as $tpl) {
                    if ($tpl['channel'] === $channel) {
                        return [
                            'subject' => $tpl['subject'] ?? null,
                            'body' => $tpl['body'],
                            'click_url' => $tpl['click_url'] ?? null,
                            'recipients' => $tpl['recipients'] ?? null,
                        ];
                    }
                }
            }
        }

        return [];
    }

    /**
     * 기본 정의 배열에 활성 언어팩 seed 로케일을 병합합니다.
     *
     * 코어 소유 정의는 활성 코어 팩(injectNotifications), 확장 소유 정의는
     * 해당 확장의 활성 모듈/플러그인 팩(injectExtensionNotifications)이 공급한다.
     * definition type 은 전역 고유(UNIQUE)이므로 확장별 호출이 다른 확장의
     * 정의에 오병합될 여지는 없다.
     *
     * @param  array<int, array<string, mixed>>  $definitions  type 필드를 포함한 기본 정의 배열
     * @return array<int, array<string, mixed>> 활성 팩 로케일이 병합된 정의 배열
     */
    private function mergeLanguagePackLocalesIntoDefaults(array $definitions): array
    {
        $definitions = $this->seedInjector->injectNotifications($definitions);

        $extensionIdentifiers = [];
        foreach ($definitions as $def) {
            $identifier = is_array($def) ? ($def['extension_identifier'] ?? null) : null;
            if (is_string($identifier) && $identifier !== '' && $identifier !== 'core') {
                $extensionIdentifiers[$identifier] = true;
            }
        }

        foreach (array_keys($extensionIdentifiers) as $identifier) {
            $definitions = $this->seedInjector->injectExtensionNotifications($definitions, $identifier);
        }

        return $definitions;
    }

    /**
     * 미리보기용 렌더링.
     *
     * definition_id가 제공되면 해당 정의의 변수 메타데이터를 조회하여
     * 샘플 값으로 치환합니다.
     *
     * @param  array  $data
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
                        $replacements['{'.$key.'}'] = '['.$description.']';
                    }
                }
            }
        }

        // 명시적으로 전달된 variables가 있으면 덮어쓰기
        foreach ($data['variables'] ?? [] as $key => $value) {
            $replacements['{'.$key.'}'] = (string) $value;
        }

        return [
            'subject' => strtr($subject, $replacements),
            'body' => strtr($body, $replacements),
        ];
    }

    /**
     * 특정 타입+채널의 캐시 무효화.
     *
     * @param  string  $type
     * @param  string  $channel
     * @return void 반환값 없음
     */
    public function invalidateCache(string $type, string $channel): void
    {
        $this->cache->forget($this->getCacheKey($type, $channel));
    }

    /**
     * 캐시 키 생성.
     *
     * @param  string  $type
     * @param  string  $channel
     * @return string
     */
    private function getCacheKey(string $type, string $channel): string
    {
        return $this->cachePrefix.$type.'.'.$channel;
    }
}
