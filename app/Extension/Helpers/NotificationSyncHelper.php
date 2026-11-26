<?php

namespace App\Extension\Helpers;

use App\Extension\Helpers\Concerns\RecordsSyncDeletion;
use App\Extension\HookManager;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Builder;
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
    use RecordsSyncDeletion;

    /**
     * 알림 정의 시딩 공통 필터 훅 이름 (7.1.0).
     *
     * 코어 시더와 모든 모듈·플러그인의 알림 정의 동기화가 DB 에 쓰기 직전 이 필터를 한 번
     * 통과시킨다. 알림 채널을 제공하는 확장은 도메인 이름을 모른 채 이 훅 하나에 답해 자기
     * 채널의 template 을 정의에 더한다 — 도메인별 시딩 훅(`seed.{id}.notifications.translations`)은
     * 언어팩 다국어 병합용이며 채널 확장이 구독할 대상이 아니다.
     */
    public const SEED_DEFINITIONS_FILTER = 'core.notification.filter_seed_definitions';

    /**
     * 시딩 직전 알림 정의 목록을 공통 필터 훅에 통과시킵니다.
     *
     * 각 정의에 소유자(`extension_type`·`extension_identifier`)를 채운 순차 배열로 넘기고,
     * 같은 소유자를 두 번째 인자(context)로도 싣는다. 리스너가 배열이 아닌 값이나 빈 배열을
     * 돌려주면 원본을 그대로 쓴다(빈 목록은 stale 정리가 소유자 정의를 모두 지우는 경로라 막는다).
     * 정의 목록이 비어 있으면 훅을 발화하지 않는다(시딩할 정의가 없음).
     *
     * @param  array<int|string, array<string, mixed>>  $definitions  시딩할 알림 정의 목록(각 항목에 type 포함)
     * @param  string  $extensionType  정의 소유자 유형 (core|module|plugin)
     * @param  string  $extensionIdentifier  정의 소유자 식별자 (코어는 'core')
     * @return array<int, array<string, mixed>> 필터를 거친 순차 배열 정의 목록
     */
    public function applySeedDefinitionsFilter(array $definitions, string $extensionType, string $extensionIdentifier): array
    {
        $list = [];
        foreach ($definitions as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $definition['extension_type'] = $extensionType;
            $definition['extension_identifier'] = $extensionIdentifier;
            $list[] = $definition;
        }

        if ($list === []) {
            return [];
        }

        $filtered = HookManager::applyFilters(
            self::SEED_DEFINITIONS_FILTER,
            $list,
            [
                'extension_type' => $extensionType,
                'extension_identifier' => $extensionIdentifier,
            ]
        );

        if (! is_array($filtered)) {
            return $list;
        }

        // 데이터 손실 방어 — 빈 목록으로 저장하면 뒤따르는 stale 정리가 소유자의 정의를 전부 지운다.
        if ($filtered === []) {
            Log::warning('알림 정의 시딩 훅이 빈 목록을 반환해 원본 정의로 시딩합니다 (데이터 손실 방어)', [
                'extension_type' => $extensionType,
                'extension_identifier' => $extensionIdentifier,
            ]);

            return $list;
        }

        return array_values($filtered);
    }

    /**
     * 알림 정의를 동기화합니다 (user_overrides 보존 upsert).
     *
     * 신규: 생성
     * 기존: user_overrides 에 없는 필드만 업데이트
     *
     * @param  array<string, mixed>  $data  definition 데이터 (type, hook_prefix, extension_type,
     *                                      extension_identifier, category, name, description, variables,
     *                                      channels, hooks, is_active, is_default, templates 포함 가능)
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
                // 분류 — 본인인증 알림은 'identity', 일반 알림은 null(선언 생략).
                'category' => $data['category'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'variables' => $data['variables'] ?? [],
                'channels' => $data['channels'] ?? ['mail'],
                'hooks' => $data['hooks'] ?? [],
                // 「관련 사용자」가 고를 수 있는 relation 키. 선언하지 않으면 화면이 그
                // 타입 자체를 감춘다 — 지원하지 않는 키를 고르면 수신자가 0명이 되고
                // 예외도 로그도 남지 않기 때문이다.
                'relations' => $data['relations'] ?? $this->relationsFromTemplates($data),
                'is_active' => $data['is_active'] ?? true,
                'is_default' => $data['is_default'] ?? true,
            ]
        );
    }

    /**
     * 선언에 `relations` 가 없으면 기본 템플릿이 실제로 쓰는 relation 키에서 유도합니다.
     *
     * 선언을 손으로 두 벌 적게 하지 않기 위해서다 — 템플릿이 `related_user`/`post_author`
     * 를 쓰는데 목록에 `post_author` 를 빠뜨리면, 운영자가 그 규칙을 편집하는 순간
     * **선언 밖 값**이 되어 저장이 막힌다. 확장이 "기본 템플릿은 안 쓰지만 운영자는 고를 수
     * 있는" 키를 열고 싶으면 `relations` 를 명시하면 되고, 그때는 이 유도가 쓰이지 않는다.
     *
     * @param  array<string, mixed>  $data  정의 선언 (templates 포함)
     * @return array<int, string> relation 키 목록 (등장 순서, 중복 제거)
     */
    private function relationsFromTemplates(array $data): array
    {
        $relations = [];

        foreach ($data['templates'] ?? [] as $template) {
            foreach ($template['recipients'] ?? [] as $rule) {
                if (($rule['type'] ?? null) !== 'related_user') {
                    continue;
                }

                $relation = $rule['relation'] ?? null;

                if (is_string($relation) && $relation !== '' && ! in_array($relation, $relations, true)) {
                    $relations[] = $relation;
                }
            }
        }

        return $relations;
    }

    /**
     * 알림 템플릿을 동기화합니다 (user_overrides 보존 upsert).
     *
     * @param  int  $definitionId  연결된 definition id
     * @param  array<string, mixed>  $data  template 데이터 (channel, subject, body,
     *                                      click_url, recipients, is_active, is_default)
     * @return NotificationTemplate 동기화된 template
     */
    public function syncTemplate(int $definitionId, array $data): NotificationTemplate
    {
        $template = NotificationTemplate::syncOrCreateFromUpgrade(
            ['definition_id' => $definitionId, 'channel' => $data['channel']],
            [
                'subject' => $data['subject'] ?? null,
                'body' => $data['body'],
                'click_url' => $data['click_url'] ?? null,
                'recipients' => $data['recipients'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'is_default' => $data['is_default'] ?? true,
                // 운영자가 먼저 켜서 만든 행이라도 확장이 그 수단을 선언하면 선언 행이 된다 — 운영자가 고친 칸은
                // user_overrides 로 보존되고, 이후 stale 정리도 선언 기준으로 다룬다.
                'is_user_created' => false,
            ]
        );

        return $this->reconcileDefaultFlags($template);
    }

    /**
     * seeder/정의에 없는 stale 알림 정의를 삭제합니다 (완전 동기화 원칙).
     *
     * 정책: config/seeder 에 없는 정의는 삭제한다. 그 정의의 템플릿은 모델 순회로 먼저 명시 삭제한다(캐시 무효화 이벤트 —
     * DB CASCADE 에 기대지 않는다). 정의·템플릿에 운영자가 고친 필드(`user_overrides`)나 운영자가 만든 템플릿
     * (`is_user_created`)가 있으면 지우기 직전 활동 로그 `notification_definition.sync_deleted` 에 정의와 모든 템플릿을 남긴다.
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
        // 검수 서버(stage)는 공유 DB 의 선언 항목을 지우지 않고 대장에 미룬다 — 운영 서버가 extension:apply-deferred-cleanup 으로 같은 규칙대로 지운다
        if (DeferredDeclarativeCleanup::shouldDefer()) {
            return DeferredDeclarativeCleanup::defer($this, __FUNCTION__, func_get_args());
        }

        $targets = $this->staleDefinitionsQuery($extensionType, $extensionIdentifier, $currentTypes)->get();
        foreach ($targets as $def) {
            // 템플릿을 모델 순회로 먼저 지운다(캐시 무효화 이벤트가 행마다 돈다) — DB CASCADE 에 기대지 않는다.
            // audit:allow service-direct-data-access reason: 선언형 동기화 정리의 하위 트리·자식 조회 — 「선언에서 빠진 행과 그 자식」 판정이 정리와 미리보기(planCleanup)에서 같은 식이어야 해 동기화 계층에 둔다
            $templates = NotificationTemplate::query()->where('definition_id', $def->id)->get();

            if ($this->definitionHasOperatorData($def, $templates)) {
                $this->recordSyncDeletion('notification_definition', (string) $def->type, $extensionType, $extensionIdentifier, [
                    'definition' => $def->toArray(),
                    'templates' => $templates->map(fn (NotificationTemplate $t) => $t->toArray())->all(),
                ]);
            }

            foreach ($templates as $template) {
                $template->delete();
            }

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
     * 정책: seeder 에 없는 (definition_id, channel) 조합은 삭제한다 — definition 은 유지되지만 channel 이 재구성된 경우에
     * 쓴다. 운영자가 고친 템플릿(`user_overrides`)이면 지우기 직전 활동 로그 `notification_template.sync_deleted` 에 남긴다.
     * 운영자가 발송 수단을 켜서 만든 행(`is_user_created`)은 처음부터 선언에 없으므로 대상이 아니다 —
     * 지우면 확장을 업데이트할 때마다 운영자가 써 둔 템플릿이 사라진다. 정의가 지워질 때는 정의 정리가 명시적으로 함께 지운다.
     *
     * @param  int  $definitionId  대상 definition id
     * @param  array<int, string>  $currentChannels  현재 유효한 channel 목록
     * @return int 삭제된 template 수
     */
    public function cleanupStaleTemplates(int $definitionId, array $currentChannels): int
    {
        // 검수 서버(stage)는 공유 DB 의 선언 항목을 지우지 않고 대장에 미룬다 — 운영 서버가 extension:apply-deferred-cleanup 으로 같은 규칙대로 지운다
        if (DeferredDeclarativeCleanup::shouldDefer()) {
            return DeferredDeclarativeCleanup::defer($this, __FUNCTION__, func_get_args());
        }

        $targets = $this->staleTemplatesQuery($definitionId, $currentChannels)->get();
        foreach ($targets as $template) {
            if ($this->hasOperatorOverrides($template)) {
                // audit:allow service-direct-data-access reason: 선언형 동기화 정리의 하위 트리·자식 조회 — 「선언에서 빠진 행과 그 자식」 판정이 정리와 미리보기(planCleanup)에서 같은 식이어야 해 동기화 계층에 둔다
                $definition = NotificationDefinition::query()->find($definitionId);
                $this->recordSyncDeletion(
                    'notification_template',
                    ($definition?->type ?? (string) $definitionId).':'.$template->channel,
                    (string) ($definition?->extension_type ?? ''),
                    (string) ($definition?->extension_identifier ?? ''),
                    ['template' => $template->toArray()],
                    ['channel' => (string) $template->channel]
                );
            }

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

    /**
     * 「기본」 표시를 실제 수정 여부에 맞춥니다.
     *
     * 동기화는 선언의 `is_default`(기본 true)를 그대로 써 왔다 — 운영자가 고친 템플릿도 업데이트·언어팩 활성화 뒤
     * 「기본」 으로 보여 「기본값 복원」 버튼이 숨었다. 템플릿은 수정 표식(user_overrides)이 없을 때만 기본이고,
     * 정의는 소속 템플릿이 전부 기본일 때만 기본이다.
     *
     * @param  NotificationTemplate  $template  동기화된 템플릿
     * @return NotificationTemplate 표시를 맞춘 템플릿
     */
    private function reconcileDefaultFlags(NotificationTemplate $template): NotificationTemplate
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

    /**
     * 지워질 stale 알림 정의를 미리 계산합니다 — 쓰기 없음, 실제 정리와 같은 조회식.
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @param  array<int, string>  $currentTypes  새 판이 선언한 type 목록
     * @return list<array{type: string, key: string, has_operator_overrides: bool}> 삭제 예정
     */
    public function planCleanup(string $extensionType, string $extensionIdentifier, array $currentTypes): array
    {
        $plan = [];

        foreach ($this->staleDefinitionsQuery($extensionType, $extensionIdentifier, $currentTypes)->get() as $def) {
            // audit:allow service-direct-data-access reason: 선언형 동기화 정리의 하위 트리·자식 조회 — 「선언에서 빠진 행과 그 자식」 판정이 정리와 미리보기(planCleanup)에서 같은 식이어야 해 동기화 계층에 둔다
            $templates = NotificationTemplate::query()->where('definition_id', $def->id)->get();
            $plan[] = ['type' => 'notification_definition', 'key' => (string) $def->type, 'has_operator_overrides' => $this->definitionHasOperatorData($def, $templates)];
        }

        return $plan;
    }

    /**
     * 남는 정의에서 지워질 stale 알림 템플릿을 미리 계산합니다 — 쓰기 없음, `cleanupStaleTemplates()` 와 같은 조회식.
     *
     * 동기화처럼 공통 시딩 훅(채널 제공 확장이 더하는 템플릿)을 거친 선언의 발송 수단을 남길 목록으로 쓴다 — 훅을 빼면
     * 다른 확장이 더한 발송 수단이 지워질 것처럼 보인다. 아직 DB 에 없는 정의(새로 생길 정의)는 지울 템플릿이 없다.
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @param  array<int, array<string, mixed>>  $definitions  새 판 알림 정의 선언
     * @return list<array{type: string, key: string, has_operator_overrides: bool}> 삭제 예정 (`{정의 type}:{발송 수단}`)
     */
    public function planTemplateCleanup(string $extensionType, string $extensionIdentifier, array $definitions): array
    {
        $plan = [];

        foreach ($this->applySeedDefinitionsFilter($definitions, $extensionType, $extensionIdentifier) as $data) {
            if (! is_string($data['type'] ?? null)) {
                continue;
            }

            // audit:allow service-direct-data-access reason: 선언형 동기화 정리의 하위 트리·자식 조회 — 「선언에서 빠진 행과 그 자식」 판정이 정리와 미리보기(planCleanup)에서 같은 식이어야 해 동기화 계층에 둔다
            $definition = NotificationDefinition::query()->where('type', $data['type'])->first();
            if ($definition === null) {
                continue;
            }

            $channels = [];
            foreach ($data['templates'] ?? [] as $template) {
                $channels[] = $template['channel'];
            }

            foreach ($this->staleTemplatesQuery($definition->id, $channels)->get() as $template) {
                $plan[] = ['type' => 'notification_template', 'key' => $data['type'].':'.$template->channel, 'has_operator_overrides' => $this->hasOperatorOverrides($template)];
            }
        }

        return $plan;
    }

    /**
     * stale 정의 조회식 — 정리와 미리보기가 함께 쓴다.
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @param  array<int, string>  $currentTypes  유효한 type 목록
     * @return Builder<NotificationDefinition> 조회식
     */
    private function staleDefinitionsQuery(string $extensionType, string $extensionIdentifier, array $currentTypes)
    {
        // audit:allow service-direct-data-access reason: 선언형 산출물 동기화 헬퍼의 「선언에 없는 것」 조회식 — 정리와 미리보기가 같은 식을 쓴다
        return NotificationDefinition::query()
            ->where('extension_type', $extensionType)
            ->where('extension_identifier', $extensionIdentifier)
            ->whereNotIn('type', $currentTypes);
    }

    /**
     * stale 템플릿 조회식 — 운영자가 만든 템플릿은 대상이 아니다.
     *
     * @param  int  $definitionId  정의 ID
     * @param  array<int, string>  $currentChannels  유효한 channel 목록
     * @return Builder<NotificationTemplate> 조회식
     */
    private function staleTemplatesQuery(int $definitionId, array $currentChannels)
    {
        // audit:allow service-direct-data-access reason: 위 staleDefinitionsQuery 와 같은 축
        return NotificationTemplate::query()
            ->where('definition_id', $definitionId)
            ->where('is_user_created', false)
            ->whereNotIn('channel', $currentChannels);
    }

    /**
     * 정의에 운영자 데이터(고친 필드·운영자가 만든 템플릿)가 있는지 판정합니다.
     *
     * @param  NotificationDefinition  $definition  정의
     * @param  iterable<NotificationTemplate>  $templates  그 정의의 템플릿
     * @return bool 있으면 true
     */
    private function definitionHasOperatorData(NotificationDefinition $definition, iterable $templates): bool
    {
        if ($this->hasOperatorOverrides($definition)) {
            return true;
        }

        foreach ($templates as $template) {
            if ($this->hasOperatorOverrides($template) || (bool) $template->is_user_created) {
                return true;
            }
        }

        return false;
    }
}
