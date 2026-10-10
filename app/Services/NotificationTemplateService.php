<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use App\Contracts\Repositories\NotificationTemplateRepositoryInterface;
use App\Extension\HookManager;
use App\Models\Concerns\UserOverrideValueComparator;
use App\Models\NotificationTemplate;
use App\Notifications\Identity\IdentityNotificationType;
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

        $legacyIdentity = $this->isIdentityTemplate($template);

        HookManager::doAction('core.notification_template.before_update', $template, $data);
        if ($legacyIdentity) {
            // 7.0.x 본인인증 메시지 관리 훅 — 공개 훅은 메이저에서만 지우므로 본인인증 분류에서는 옛 이름도 같은 인자로 함께 발화한다(@deprecated 7.1.0, 8.0 제거).
            HookManager::doAction('core.identity.message_template.before_update', $template, $data);
        }

        $data = HookManager::applyFilters(
            'core.notification_template.filter_update_data',
            $data,
            $template
        );
        if ($legacyIdentity) {
            $data = HookManager::applyFilters('core.identity.message_template.filter_update_data', $data, $template);
        }

        if ($userId) {
            $data['updated_by'] = $userId;
        }

        $updated = $this->templateRepository->update($template, $data);
        $updated = $this->releaseOverridesMatchingDeclaration($updated);

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
            // 고친 문구를 선언값으로 되돌려 템플릿이 「기본」 으로 돌아왔으면, 소속 템플릿이 전부 기본일 때 정의도
            // 기본으로 맞춘다(동기화 보정 NotificationSyncHelper::reconcileDefaultFlags 와 같은 규칙). 선언이 없는
            // 정의(운영자가 추가한 정책별 문구)는 「기본」 이 삭제 불가를 뜻하므로 건드리지 않는다.
            if (! $customized && ! $definition->is_default
                && $this->findDeclaredTemplate($definition->type, $updated->channel) !== null
                && ! $this->templateRepository->hasNonDefaultByDefinitionId((int) $definition->id)) {
                $this->definitionRepository->update($definition, ['is_default' => true]);
            }

            $this->invalidateCache($definition->type, $updated->channel);
        }

        HookManager::doAction('core.notification_template.after_update', $updated, $data, $snapshot);
        if ($legacyIdentity) {
            HookManager::doAction('core.identity.message_template.after_update', $updated, $data);
        }

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
        $legacyIdentity = $this->isIdentityTemplate($template);

        HookManager::doAction('core.notification_template.before_toggle_active', $template);
        if ($legacyIdentity) {
            // 7.0.x 본인인증 메시지 관리 훅 — 공개 훅은 메이저에서만 지우므로 본인인증 분류에서는 옛 이름도 같은 인자로 함께 발화한다(@deprecated 7.1.0, 8.0 제거).
            HookManager::doAction('core.identity.message_template.before_toggle_active', $template);
        }

        $updated = $this->templateRepository->update($template, [
            'is_active' => ! $template->is_active,
        ]);
        // 끈 것을 다시 켜 선언값으로 돌아오면 「수정됨」 표식도 푼다 — 남겨 두면 이어서 저장만 해도 「기본」 이 풀린다.
        $updated = $this->releaseOverridesMatchingDeclaration($updated);

        $definition = $updated->definition;
        if ($definition) {
            $this->invalidateCache($definition->type, $updated->channel);
        }

        HookManager::doAction('core.notification_template.after_toggle_active', $updated);
        if ($legacyIdentity) {
            HookManager::doAction('core.identity.message_template.after_toggle_active', $updated);
        }

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
        $legacyIdentity = $this->isIdentityTemplate($template);
        if ($legacyIdentity) {
            // 7.0.x 본인인증 메시지 관리 훅 — 공개 훅은 메이저에서만 지우므로 본인인증 분류에서는 옛 이름도 같은 인자로 함께 발화한다(@deprecated 7.1.0, 8.0 제거).
            HookManager::doAction('core.identity.message_template.before_reset', $template);
        }

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

        if ($legacyIdentity) {
            HookManager::doAction('core.identity.message_template.after_reset', $updated);
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
        $tpl = $this->findDeclaredTemplate($type, $channel);
        if ($tpl === null) {
            return [];
        }

        return [
            'subject' => $tpl['subject'] ?? null,
            'body' => $tpl['body'],
            'click_url' => $tpl['click_url'] ?? null,
            'recipients' => $tpl['recipients'] ?? null,
        ];
    }

    /**
     * 정의 선언(코어 config·확장 필터·활성 언어팩 병합)에서 그 유형·채널의 템플릿 선언을 찾습니다.
     *
     * @param  string  $type  알림 정의 타입
     * @param  string  $channel  채널명
     * @return array<string, mixed>|null 템플릿 선언(없으면 null)
     */
    private function findDeclaredTemplate(string $type, string $channel): ?array
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

        // 코어 정의에는 필터 전에 활성 코어 언어팩 로케일을 먼저 병합한다 — 시딩 경로(번역 병합 →
        // filter_seed_definitions)와 같은 순서. 채널 확장(문자·알림톡)은 이 필터에서 메일 문구를 복사해
        // 자기 채널 템플릿을 만들므로, 병합이 필터 뒤에만 있으면 복사본에 ja 등 팩 로케일이 빠져
        // [기본값 복원]이 그 채널의 팩 번역을 지운다(2026-10-06 실측). 필터 뒤 병합은 확장이 덧붙인
        // 정의를 위해 그대로 둔다(병합은 같은 값을 다시 써도 결과가 같다).
        $definitions = $this->seedInjector->injectNotifications($definitions);

        // 확장(모듈/플러그인)이 자신의 기본 정의를 추가할 수 있도록 필터 훅 노출
        $definitions = HookManager::applyFilters(
            'core.notification.filter_default_definitions',
            $definitions,
            ['type' => $type, 'channel' => $channel]
        );

        // 7.0.x 본인인증 메시지 기본값 필터 — 본인인증 분류만 옛 이름으로도 거른다(@deprecated 7.1.0, 8.0 제거).
        $identityDefaults = array_values(array_filter($definitions, fn ($d) => ($d['category'] ?? null) === IdentityNotificationType::CATEGORY));
        if ($identityDefaults !== []) {
            $filtered = HookManager::applyFilters('core.identity.filter_default_message_definitions', $identityDefaults, []);
            if (is_array($filtered)) {
                $definitions = array_merge(
                    array_values(array_filter($definitions, fn ($d) => ($d['category'] ?? null) !== IdentityNotificationType::CATEGORY)),
                    $filtered
                );
            }
        }

        // 활성 언어팩 seed 로케일 병합 — 시딩(seed.notifications.translations)과 같은 SSoT.
        // 이 병합이 없으면 [기본값 복원]이 팩이 주입해 둔 로케일(ja 등)을 config 의
        // ko/en 만으로 대체해 영구 소실시킨다 (2026-08-23 실사례 — #597 보완 실측).
        $definitions = $this->mergeLanguagePackLocalesIntoDefaults($definitions);

        foreach ($definitions as $def) {
            if ($def['type'] === $type) {
                foreach ($def['templates'] as $tpl) {
                    if ($tpl['channel'] === $channel) {
                        return $tpl;
                    }
                }
            }
        }

        return null;
    }

    /**
     * 지금 값이 선언(기본) 값과 같아진 칸의 「수정됨」 표식을 풉니다.
     *
     * 표식은 「직전 값과 달라진 칸」 으로 쌓이므로, 껐다 다시 켜거나 고친 문구를 원래대로 되돌려도 남는다.
     * 남은 표식은 저장만 해도 「기본」 을 풀고 새 버전 교정을 막는다 — 업데이트 때의 합의 해제 규칙
     * (HasUserOverrides::planUpgrade)과 같은 기준을 저장 직후에도 적용한다. 선언이 없는 템플릿(운영자가 추가한
     * 정책별 문구 등)은 그대로 둔다.
     *
     * @param  NotificationTemplate  $template  방금 저장한 템플릿
     * @return NotificationTemplate 표식을 정리한 템플릿
     */
    private function releaseOverridesMatchingDeclaration(NotificationTemplate $template): NotificationTemplate
    {
        $overrides = $template->user_overrides ?? [];
        $definition = $template->definition;
        if ($overrides === [] || ! $definition) {
            return $template;
        }

        $declared = $this->findDeclaredTemplate($definition->type, $template->channel);
        if ($declared === null) {
            return $template;
        }
        // 선언에 켜기/끄기가 없으면 켜진 상태로 심는다(NotificationSyncHelper 와 같은 기본값).
        $declared['is_active'] = (bool) ($declared['is_active'] ?? true);

        $remaining = [];
        foreach ($overrides as $entry) {
            [$field, $locale] = array_pad(explode('.', (string) $entry, 2), 2, null);
            if (! array_key_exists($field, $declared) && ! in_array($field, ['click_url', 'recipients'], true)) {
                $remaining[] = $entry;

                continue;
            }

            $current = $template->getAttribute($field);
            $target = $declared[$field] ?? null;
            if ($locale !== null) {
                if (! is_array($target) || ! array_key_exists($locale, $target)) {
                    $remaining[] = $entry;

                    continue;
                }
                $current = is_array($current) ? ($current[$locale] ?? null) : null;
                $target = $target[$locale];
            }

            if (! UserOverrideValueComparator::same(
                $template->comparableTrackableValue($field, $current),
                $template->comparableTrackableValue($field, $target),
            )) {
                $remaining[] = $entry;
            }
        }

        if ($remaining === $overrides) {
            return $template;
        }

        // 표식 정리는 운영자 편집이 아니다 — 시더 플래그로 HasUserOverrides 의 기록을 끄고 저장한다.
        app()->instance('user_overrides.seeding', true);
        try {
            $template = $this->templateRepository->update($template, [
                'user_overrides' => $remaining === [] ? null : $remaining,
            ]);
        } finally {
            app()->forgetInstance('user_overrides.seeding');
        }

        return $template;
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

    /**
     * 기본값이 없어 복원하지 못한 본인인증 템플릿을 옛 훅으로 알립니다(@deprecated 7.1.0, 8.0 제거).
     *
     * @param  NotificationTemplate  $template  복원하지 못한 템플릿
     * @return void 반환값 없음
     */
    public function notifyLegacyResetWithoutDefault(NotificationTemplate $template): void
    {
        if ($this->isIdentityTemplate($template)) {
            HookManager::doAction('core.identity.message_template.reset_no_default', $template);
        }
    }

    /**
     * 템플릿이 본인인증 분류 정의에 속하는지 판정합니다(옛 본인인증 메시지 훅 병행 발화용).
     *
     * @param  NotificationTemplate  $template  대상 템플릿
     * @return bool 본인인증 분류면 true
     */
    private function isIdentityTemplate(NotificationTemplate $template): bool
    {
        return $template->definition?->category === IdentityNotificationType::CATEGORY;
    }
}
