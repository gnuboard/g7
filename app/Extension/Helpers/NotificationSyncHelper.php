<?php

namespace App\Extension\Helpers;

use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\Log;

/**
 * 알림 정의/템플릿 동기화 헬퍼.
 *
 * 코어/확장 업그레이드 경로에서 알림 정의·템플릿을 동기화합니다.
 * ExtensionMenuSyncHelper, ExtensionRoleSyncHelper 와 동일한 Helper 패턴을
 * 따르며, 내부 upsert 는 `HasUserOverrides::syncOrCreateFromUpgrade` 에 위임하여
 * trait 공통 API 를 재활용합니다.
 *
 * Seeder 는 본 helper 를 호출하는 얇은 진입점이며, 모든 데이터 정합성 로직은
 * helper 에 집중됩니다.
 */
class NotificationSyncHelper
{
    /**
     * 알림 정의를 동기화합니다 (user_overrides 보존 upsert).
     *
     * 신규: 생성
     * 기존: user_overrides 에 없는 필드만 업데이트
     *
     * @param  array<string, mixed>  $data  definition 데이터 (type, hook_prefix, extension_type,
     *                                       extension_identifier, name, description, variables,
     *                                       channels, hooks, is_active, is_default, templates 포함 가능)
     * @return NotificationDefinition 동기화된 definition
     */
    public function syncDefinition(array $data): NotificationDefinition
    {
        return NotificationDefinition::syncOrCreateFromUpgrade(
            ['type' => $data['type']],
            [
                'hook_prefix' => $data['hook_prefix'],
                'extension_type' => $data['extension_type'],
                'extension_identifier' => $data['extension_identifier'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'variables' => $data['variables'] ?? [],
                'channels' => $data['channels'] ?? ['mail'],
                'hooks' => $data['hooks'] ?? [],
                'is_active' => $data['is_active'] ?? true,
                'is_default' => $data['is_default'] ?? true,
            ]
        );
    }

    /**
     * 알림 템플릿을 동기화합니다 (user_overrides 보존 upsert).
     *
     * @param  int  $definitionId  연결된 definition id
     * @param  array<string, mixed>  $data  template 데이터 (channel, subject, body,
     *                                       click_url, recipients, is_active, is_default)
     * @return NotificationTemplate 동기화된 template
     */
    public function syncTemplate(int $definitionId, array $data): NotificationTemplate
    {
        return NotificationTemplate::syncOrCreateFromUpgrade(
            ['definition_id' => $definitionId, 'channel' => $data['channel']],
            [
                'subject' => $data['subject'] ?? null,
                'body' => $data['body'],
                'click_url' => $data['click_url'] ?? null,
                'recipients' => $data['recipients'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'is_default' => $data['is_default'] ?? true,
            ]
        );
    }

    /**
     * seeder/정의에 없는 stale 알림 정의를 삭제합니다 (완전 동기화 원칙).
     *
     * 정책: `user_overrides` 무관 — config/seeder 에 없는 정의는 삭제.
     * FK cascade 로 연관 `notification_templates` 도 자동 정리됩니다.
     *
     * @param  string  $extensionType  확장 타입: 'core', 'module', 'plugin'
     * @param  string  $extensionIdentifier  확장 식별자
     * @param  array<int, string>  $currentTypes  현재 유효한 definition type 목록
     * @return int 삭제된 definition 수
     */
    public function cleanupStaleDefinitions(
        string $extensionType,
        string $extensionIdentifier,
        array $currentTypes,
    ): int {
        $query = NotificationDefinition::query()
            ->where('extension_type', $extensionType)
            ->where('extension_identifier', $extensionIdentifier)
            ->whereNotIn('type', $currentTypes);

        $targets = $query->get(['id', 'type']);
        foreach ($targets as $def) {
            $def->delete();
        }

        $count = $targets->count();
        if ($count > 0) {
            Log::info('stale 알림 정의 정리 완료', [
                'extension_type' => $extensionType,
                'extension_identifier' => $extensionIdentifier,
                'deleted' => $count,
                'types' => $targets->pluck('type')->all(),
            ]);
        }

        return $count;
    }

    /**
     * 주어진 definition 의 channel 목록 기준으로 stale template 을 삭제합니다.
     *
     * 정책: `user_overrides` 무관 — seeder 에 없는 (definition_id, channel)
     * 조합은 삭제. definition 삭제 시 FK cascade 로 자동 정리되는 경로와 별개로,
     * definition 은 유지되지만 channel 이 재구성된 경우에 사용됩니다.
     *
     * @param  int  $definitionId
     * @param  array<int, string>  $currentChannels  현재 유효한 channel 목록
     * @return int 삭제된 template 수
     */
    public function cleanupStaleTemplates(int $definitionId, array $currentChannels): int
    {
        $query = NotificationTemplate::query()
            ->where('definition_id', $definitionId)
            ->whereNotIn('channel', $currentChannels);

        $targets = $query->get(['id', 'channel']);
        foreach ($targets as $template) {
            $template->delete();
        }

        $count = $targets->count();
        if ($count > 0) {
            Log::info('stale 알림 템플릿 정리 완료', [
                'definition_id' => $definitionId,
                'deleted' => $count,
                'channels' => $targets->pluck('channel')->all(),
            ]);
        }

        return $count;
    }
}
