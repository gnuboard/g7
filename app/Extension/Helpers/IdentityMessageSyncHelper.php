<?php

namespace App\Extension\Helpers;

use App\Models\IdentityMessageDefinition;
use App\Models\IdentityMessageTemplate;
use Illuminate\Support\Facades\Log;

/**
 * IDV 메시지 정의/템플릿 동기화 헬퍼.
 *
 * 알림 시스템(NotificationSyncHelper)과 분리된 IDV 전용 헬퍼.
 * (provider_id, scope_type, scope_value) 매트릭스를 키로 사용합니다.
 *
 * Seeder 는 본 helper 를 호출하는 얇은 진입점이며, 모든 데이터 정합성 로직은
 * helper 에 집중됩니다. 내부 upsert 는 `HasUserOverrides::syncOrCreateFromUpgrade`
 * 에 위임하여 trait 공통 API 를 재활용합니다.
 */
class IdentityMessageSyncHelper
{
    /**
     * 메시지 정의를 동기화합니다 (user_overrides 보존 upsert).
     *
     * @param  array<string, mixed>  $data  definition 데이터 (provider_id, scope_type, scope_value,
     *                                      extension_type, extension_identifier, name, description,
     *                                      channels, variables, is_active, is_default, templates 포함 가능)
     * @return IdentityMessageDefinition
     */
    public function syncDefinition(array $data): IdentityMessageDefinition
    {
        $scopeValue = (string) ($data['scope_value'] ?? '');

        return IdentityMessageDefinition::syncOrCreateFromUpgrade(
            [
                'provider_id' => $data['provider_id'],
                'scope_type' => $data['scope_type'],
                'scope_value' => $scopeValue,
            ],
            [
                'extension_type' => $data['extension_type'],
                'extension_identifier' => $data['extension_identifier'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'channels' => $data['channels'] ?? ['mail'],
                'variables' => $data['variables'] ?? [],
                'is_active' => $data['is_active'] ?? true,
                'is_default' => $data['is_default'] ?? true,
            ]
        );
    }

    /**
     * 메시지 템플릿을 동기화합니다 (user_overrides 보존 upsert).
     *
     * @param  int  $definitionId
     * @param  array<string, mixed>  $data  template 데이터 (channel, subject, body, is_active, is_default)
     * @return IdentityMessageTemplate
     */
    public function syncTemplate(int $definitionId, array $data): IdentityMessageTemplate
    {
        $template = IdentityMessageTemplate::syncOrCreateFromUpgrade(
            ['definition_id' => $definitionId, 'channel' => $data['channel']],
            [
                'subject' => $data['subject'] ?? null,
                'body' => $data['body'],
                'is_active' => $data['is_active'] ?? true,
                'is_default' => $data['is_default'] ?? true,
            ]
        );

        return $this->reconcileDefaultFlags($template);
    }

    /**
     * stale 메시지 정의를 삭제합니다 (완전 동기화 원칙).
     *
     * 정책: `user_overrides` 무관 — config/seeder 에 없는 정의는 삭제.
     * FK cascade 로 연관 templates 도 자동 정리됩니다.
     *
     * @param  string  $extensionType
     * @param  string  $extensionIdentifier
     * @param  array<int, array{provider_id: string, scope_type: string, scope_value: string}>  $currentScopes
     * @return int 삭제된 definition 수
     */
    public function cleanupStaleDefinitions(
        string $extensionType,
        string $extensionIdentifier,
        array $currentScopes,
    ): int {
        $currentKeys = array_map(
            fn (array $s) => $s['provider_id'].'|'.$s['scope_type'].'|'.((string) ($s['scope_value'] ?? '')),
            $currentScopes
        );

        // audit:allow service-direct-data-access reason: 선언형 산출물 동기화 헬퍼다 — 확장이 선언에서 지운 정의를 찾아 지우는 정리 쿼리이며(NotificationSyncHelper::cleanupStaleDefinitions 와 같은 축), "선언에 없는 것" 이라는 조건을 동기화 계층과 저장 계층으로 가르지 않는다
        $query = IdentityMessageDefinition::query()
            ->where('extension_type', $extensionType)
            ->where('extension_identifier', $extensionIdentifier);

        $targets = $query->get(['id', 'provider_id', 'scope_type', 'scope_value']);

        $stale = $targets->filter(function (IdentityMessageDefinition $def) use ($currentKeys) {
            $key = $def->provider_id.'|'.$def->scope_type->value.'|'.((string) $def->scope_value);

            return ! in_array($key, $currentKeys, true);
        });

        foreach ($stale as $def) {
            $def->delete();
        }

        $count = $stale->count();
        if ($count > 0) {
            Log::info('stale IDV 메시지 정의 정리 완료', [
                'extension_type' => $extensionType,
                'extension_identifier' => $extensionIdentifier,
                'deleted' => $count,
                'scopes' => $stale->map(fn ($d) => $d->provider_id.'|'.$d->scope_type->value.'|'.$d->scope_value)->all(),
            ]);
        }

        return $count;
    }

    /**
     * 주어진 definition 의 channel 목록 기준으로 stale template 을 삭제합니다.
     *
     * @param  int  $definitionId
     * @param  array<int, string>  $currentChannels
     * @return int 삭제된 template 수
     */
    public function cleanupStaleTemplates(int $definitionId, array $currentChannels): int
    {
        // audit:allow service-direct-data-access reason: 위 `cleanupStaleDefinitions` 와 같은 축 — 선언에서 사라진 (definition, channel) 조합만 지우는 동기화 정리 쿼리다
        $query = IdentityMessageTemplate::query()
            ->where('definition_id', $definitionId)
            ->whereNotIn('channel', $currentChannels);

        $targets = $query->get(['id', 'channel']);
        foreach ($targets as $template) {
            $template->delete();
        }

        $count = $targets->count();
        if ($count > 0) {
            Log::info('stale IDV 메시지 템플릿 정리 완료', [
                'definition_id' => $definitionId,
                'deleted' => $count,
                'channels' => $targets->pluck('channel')->all(),
            ]);
        }

        return $count;
    }

    /**
     * 「기본」 표시를 실제 수정 여부에 맞춥니다 (#727 W12).
     *
     * 동기화는 선언의 `is_default`(기본 true)를 그대로 써 왔다 — 운영자가 고친 템플릿도 업데이트·언어팩 활성화 뒤
     * 「기본」 으로 보여 「기본값 복원」 버튼이 숨었다. 템플릿은 수정 표식(user_overrides)이 없을 때만 기본이고,
     * 정의는 소속 템플릿이 전부 기본일 때만 기본이다.
     *
     * @param  IdentityMessageTemplate  $template  동기화된 템플릿
     * @return IdentityMessageTemplate 표시를 맞춘 템플릿
     */
    private function reconcileDefaultFlags(IdentityMessageTemplate $template): IdentityMessageTemplate
    {
        $expected = empty($template->user_overrides);

        if ((bool) $template->is_default !== $expected) {
            // audit:allow service-direct-data-access reason: 동기화 단계 안의 「기본」 표시 보정이다 — 이벤트를 끈 저장(saveQuietly)이어야 운영자 수정 기록(HasUserOverrides 의 updating 이벤트)이 이 보정을 운영자 편집으로 기록하지 않는다. 이 저장은 모델 이벤트에 걸린 캐시 무효화도 건너뛰지만, 같은 동기화의 템플릿·정의 저장이 이미 무효화를 발화한다. Repository 의 update 는 이벤트를 발화한다
            $template->forceFill(['is_default' => $expected])->saveQuietly();
        }

        $definition = $template->definition;
        if ($definition !== null) {
            $definitionDefault = ! $definition->templates()->where('is_default', false)->exists();
            if ((bool) $definition->is_default !== $definitionDefault) {
                // audit:allow service-direct-data-access reason: 위와 같은 축 — 정의의 「기본」 표시를 소속 템플릿 상태에 맞추는 동기화 보정이라 이벤트 없이 저장한다
                $definition->forceFill(['is_default' => $definitionDefault])->saveQuietly();
            }
        }

        return $template;
    }
}
