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
     *                                       extension_type, extension_identifier, name, description,
     *                                       channels, variables, is_active, is_default, templates 포함 가능)
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
        return IdentityMessageTemplate::syncOrCreateFromUpgrade(
            ['definition_id' => $definitionId, 'channel' => $data['channel']],
            [
                'subject' => $data['subject'] ?? null,
                'body' => $data['body'],
                'is_active' => $data['is_active'] ?? true,
                'is_default' => $data['is_default'] ?? true,
            ]
        );
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
}
