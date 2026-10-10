<?php

namespace App\Upgrades\Data\V7_1_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * 본인인증(IDV) 메시지를 알림 정의로 옮기고 옛 표를 드랍합니다 (#699).
 *
 * 7.0.x 는 본인인증 메일 문구를 알림 밖의 `identity_message_definitions/templates` 에 두고 알림 시스템을 거치지 않고
 * 보냈습니다. 7.1.0 은 같은 문구를 알림 정의(`notification_definitions`, category=identity)로 다룹니다.
 *
 * 순서 의존 (같은 업데이트 안에서):
 *  - 마이그레이션(category 컬럼) → 재시딩(NotificationDefinitionSeeder 가 코어 `identity.*` 선언 행을 만든다)
 *    → 이 스텝. 그래서 코어 선언 행은 이미 있고, 여기서는 운영자 수정분만 옮긴다.
 *  - 03_PruneFalseUserOverrides 가 이 스텝보다 먼저 옛 표의 거짓 "수정" 표식을 지운다 — 남은 표식만 진짜 수정이다.
 *
 * 규칙:
 *  1. 옛 표가 없으면(신규 설치·재실행) 권한 정리만 하고 끝낸다.
 *  2. provider_id 가 코어 메일(`g7:core.mail`)이 아니면 경고 후 건너뛴다 — 7.0.x 디스패처는 메일 프로바이더 정의만
 *     해석했으므로 발송에 쓰인 적이 없는 행이다(번들 KCP·이니시스·TOTP 는 메시지를 선언하지 않는다).
 *  3. type = 옛 범위 → `identity.default` / `identity.purpose.{목적}` / `identity.policy.{정책 키}`(100자 초과 시
 *     `identity.policy.~{sha1 앞 20자}`). 규칙은 IdentityNotificationType 과 같고 V-1 때문에 여기 인라인으로 둔다.
 *  4. 같은 type 의 알림 정의가 있으면(재시딩이 만든 선언 행): 옛 정의의 운영자 수정 칸(name·is_active)과 표식을
 *     옮기고, 옛 템플릿이 수정됐으면(user_overrides 비지 않음) 그 내용으로 알림 템플릿을 덮는다. 수정이 없거나
 *     표식만 있고 값이 선언 기본값과 같으면(값을 바꾸지 않은 저장이 남긴 표식) 선언 기본값을 유지한다 —
 *     그 표식을 옮기면 고친 적 없는 문구가 「수정됨」 으로 굳는다. 다른 소유자의 행이면 건너뛴다.
 *  5. 없으면(운영자 정책 정의·비활성 확장 정의) 새로 만든다 — 소유 칸은 옛 행 그대로, 훅 접두사는 코어면
 *     `core.identity`, 확장이면 확장 식별자.
 *  6. 대조: 정의·템플릿 각각 `옮김 + 건너뜀 == 옛 표 전체 수`. 어긋나면 예외 — 드랍하지 않으므로 데이터가 남고
 *     업데이트는 스텝 실패로 멈춘다(운영자가 원인을 보고 재실행).
 *  7. 템플릿 표 → 정의 표 순으로 드랍, 권한 `core.admin.identity.messages.read|update` 와 역할 연결 삭제.
 *
 * V-1 판단: DB·Schema 파사드와 이 파일의 private 로직만 씁니다(모델·서비스·IdentityNotificationType 미사용).
 * idempotent: 두 번째 실행은 옛 표가 없어 권한 정리(이미 0건)만 하고 끝납니다.
 */
class MigrateIdentityMessagesToNotifications implements DataMigration
{
    /** 코어 메일 본인인증 프로바이더 */
    private const MAIL_PROVIDER = 'g7:core.mail';

    /** notification_definitions.type 컬럼 길이 */
    private const MAX_TYPE_LENGTH = 100;

    /** 제거하는 권한 */
    private const REMOVED_PERMISSIONS = ['core.admin.identity.messages.read', 'core.admin.identity.messages.update'];

    /** 본인인증 알림 공통 변수 (IdentityNotificationType::commonVariables 의 7.1.0 동결 사본) */
    private const COMMON_VARIABLES = [
        ['key' => 'code', 'description' => '인증 코드 (text_code 흐름)'],
        ['key' => 'action_url', 'description' => '검증 링크 URL (link 흐름)'],
        ['key' => 'expire_minutes', 'description' => '만료까지 남은 분'],
        ['key' => 'purpose_label', 'description' => '인증 목적 라벨 (다국어)'],
        ['key' => 'app_name', 'description' => '사이트명'],
        ['key' => 'site_url', 'description' => '사이트 URL'],
        ['key' => 'recipient_email', 'description' => '수신자 이메일'],
    ];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'MigrateIdentityMessagesToNotifications';
    }

    /**
     * 옛 본인인증 메시지를 알림 정의로 옮기고 옛 표·권한을 정리합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     *
     * @throws RuntimeException 건수 대조가 어긋났을 때 (옛 표는 드랍하지 않는다)
     */
    public function run(UpgradeContext $context): void
    {
        if (Schema::hasTable('identity_message_definitions')
            && Schema::hasTable('notification_definitions')
            && Schema::hasColumn('notification_definitions', 'category')) {
            $this->migrate($context);
        } else {
            $context->logger->info('[core:7.1.0] identity_message_definitions 부재(또는 알림 정의 category 미준비) — 이관 스킵');
        }

        $this->removePermissions($context);
        $this->invalidateCaches($context);
    }

    /**
     * 옛 정의·템플릿을 옮기고, 대조가 맞으면 옛 표를 드랍합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     *
     * @throws RuntimeException 건수 대조가 어긋났을 때
     */
    private function migrate(UpgradeContext $context): void
    {
        $definitions = DB::table('identity_message_definitions')->orderBy('id')->get();
        $templatesByDefinition = Schema::hasTable('identity_message_templates')
            ? DB::table('identity_message_templates')->orderBy('id')->get()->groupBy('definition_id')
            : collect();
        $totalTemplates = $templatesByDefinition->flatten(1)->count();

        $counts = ['def_migrated' => 0, 'def_skipped' => 0, 'tpl_migrated' => 0, 'tpl_skipped' => 0];

        DB::transaction(function () use ($context, $definitions, $templatesByDefinition, &$counts) {
            foreach ($definitions as $legacy) {
                $templates = $templatesByDefinition->get($legacy->id, collect());
                $outcome = $this->migrateDefinition($context, $legacy, $templates);

                $counts[$outcome === 'skipped' ? 'def_skipped' : 'def_migrated']++;
                $counts[$outcome === 'skipped' ? 'tpl_skipped' : 'tpl_migrated'] += $templates->count();
            }
        });

        $context->logger->info('[core:7.1.0] 본인인증 메시지 이관 결과', $counts + [
            'definitions_total' => $definitions->count(),
            'templates_total' => $totalTemplates,
        ]);

        if ($definitions->count() !== $counts['def_migrated'] + $counts['def_skipped']
            || $totalTemplates !== $counts['tpl_migrated'] + $counts['tpl_skipped']) {
            throw new RuntimeException(
                '[core:7.1.0] 본인인증 메시지 이관 건수가 맞지 않아 옛 표를 드랍하지 않았습니다 — upgrade.log 의 이관 결과를 확인한 뒤 다시 실행하세요.'
            );
        }

        Schema::dropIfExists('identity_message_templates');
        Schema::dropIfExists('identity_message_definitions');
        $context->logger->info('[core:7.1.0] identity_message_templates · identity_message_definitions 드랍 완료');
    }

    /**
     * 옛 정의 1건(과 그 템플릿)을 알림 정의로 옮깁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @param  object  $legacy  옛 정의 행
     * @param  Collection  $templates  그 정의의 옛 템플릿 행
     * @return string 'merged' | 'created' | 'skipped'
     */
    private function migrateDefinition(UpgradeContext $context, object $legacy, $templates): string
    {
        if ($legacy->provider_id !== self::MAIL_PROVIDER) {
            $context->logger->warning('[core:7.1.0] 메일 외 프로바이더의 본인인증 메시지는 발송에 쓰인 적이 없어 옮기지 않습니다', [
                'id' => $legacy->id,
                'provider_id' => $legacy->provider_id,
                'scope_type' => $legacy->scope_type,
                'scope_value' => $legacy->scope_value,
                'extension' => $legacy->extension_type.'/'.$legacy->extension_identifier,
            ]);

            return 'skipped';
        }

        $type = $this->typeFor((string) $legacy->scope_type, (string) $legacy->scope_value);
        if ($type === null) {
            $context->logger->warning('[core:7.1.0] 알 수 없는 범위의 본인인증 메시지를 옮기지 않습니다', [
                'id' => $legacy->id,
                'scope_type' => $legacy->scope_type,
                'scope_value' => $legacy->scope_value,
            ]);

            return 'skipped';
        }

        $existing = DB::table('notification_definitions')->where('type', $type)->first();

        if ($existing === null) {
            $this->createDefinition($legacy, $type, $templates);

            return 'created';
        }

        if ($existing->extension_type !== $legacy->extension_type
            || $existing->extension_identifier !== $legacy->extension_identifier) {
            $context->logger->warning('[core:7.1.0] 같은 type 을 다른 소유자가 가져 본인인증 메시지를 옮기지 않습니다', [
                'type' => $type,
                'legacy_owner' => $legacy->extension_type.'/'.$legacy->extension_identifier,
                'existing_owner' => $existing->extension_type.'/'.$existing->extension_identifier,
            ]);

            return 'skipped';
        }

        $this->mergeIntoExisting($existing, $legacy, $templates);

        return 'merged';
    }

    /**
     * 선언으로 이미 있는 알림 정의에 옛 정의·템플릿의 운영자 수정분을 옮깁니다.
     *
     * @param  object  $existing  알림 정의 행
     * @param  object  $legacy  옛 정의 행
     * @param  Collection  $templates  옛 템플릿 행
     * @return void 반환값 없음
     */
    private function mergeIntoExisting(object $existing, object $legacy, $templates): void
    {
        $legacyOverrides = $this->decodeList($legacy->user_overrides);
        $definitionUpdate = [];
        $moved = [];

        foreach ($legacyOverrides as $field) {
            $root = explode('.', (string) $field)[0];
            if ($root === 'name' && ! $this->sameLocales($legacy->name, $existing->name)) {
                $definitionUpdate['name'] = $legacy->name;
                $moved[] = $field;
            } elseif ($root === 'is_active' && (bool) $legacy->is_active !== (bool) $existing->is_active) {
                $definitionUpdate['is_active'] = (bool) $legacy->is_active;
                $moved[] = $field;
            }
        }

        $customized = false;
        $existingTemplates = DB::table('notification_templates')->where('definition_id', $existing->id)->get()->keyBy('channel');

        foreach ($templates as $template) {
            if ($this->decodeList($template->user_overrides) === []) {
                continue; // 수정되지 않은 템플릿 — 선언 기본값 유지
            }

            // 표식은 있으나 값이 선언 기본값과 같은 템플릿(값을 바꾸지 않은 저장이 남긴 표식)은 옮기지 않는다 —
            // 옮기면 고친 적 없는 문구가 「수정됨」 으로 굳어 새 버전 문구를 영영 받지 못한다.
            $current = $existingTemplates->get($template->channel);
            if ($current !== null
                && $this->sameLocales($template->subject, $current->subject)
                && $this->sameLocales($template->body, $current->body)) {
                continue;
            }

            $customized = true;
            DB::table('notification_templates')->updateOrInsert(
                ['definition_id' => $existing->id, 'channel' => $template->channel],
                [
                    'subject' => $template->subject,
                    'body' => $template->body,
                    'is_active' => (bool) $template->is_active,
                    'is_default' => false,
                    'user_overrides' => $template->user_overrides,
                    'updated_by' => $template->updated_by,
                    'updated_at' => now(),
                ]
            );
        }

        if ($moved !== []) {
            $definitionUpdate['user_overrides'] = json_encode(
                array_values(array_unique(array_merge($this->decodeList($existing->user_overrides ?? null), $moved))),
                JSON_UNESCAPED_UNICODE
            );
        }

        if ($customized) {
            $definitionUpdate['is_default'] = false;
        }

        if ($definitionUpdate !== []) {
            $definitionUpdate['updated_at'] = now();
            DB::table('notification_definitions')->where('id', $existing->id)->update($definitionUpdate);
        }
    }

    /**
     * 선언이 없는 옛 정의(운영자 정책 문구·비활성 확장 문구)를 새 알림 정의로 만듭니다.
     *
     * @param  object  $legacy  옛 정의 행
     * @param  string  $type  알림 type
     * @param  Collection  $templates  옛 템플릿 행
     * @return void 반환값 없음
     */
    private function createDefinition(object $legacy, string $type, $templates): void
    {
        $now = now();
        $variables = $legacy->variables ?: json_encode(self::COMMON_VARIABLES, JSON_UNESCAPED_UNICODE);

        $definitionId = DB::table('notification_definitions')->insertGetId([
            'type' => $type,
            'hook_prefix' => $legacy->extension_type === 'core' ? 'core.identity' : (string) $legacy->extension_identifier,
            'extension_type' => $legacy->extension_type,
            'extension_identifier' => $legacy->extension_identifier,
            'category' => 'identity',
            'name' => $legacy->name,
            'description' => $legacy->description,
            'variables' => $variables,
            'channels' => $legacy->channels ?: '["mail"]',
            'hooks' => '[]',
            'is_active' => (bool) $legacy->is_active,
            'is_default' => (bool) $legacy->is_default,
            'user_overrides' => $legacy->user_overrides,
            'created_at' => $legacy->created_at ?? $now,
            'updated_at' => $now,
        ]);

        foreach ($templates as $template) {
            DB::table('notification_templates')->insert([
                'definition_id' => $definitionId,
                'channel' => $template->channel,
                'subject' => $template->subject,
                'body' => $template->body,
                'is_active' => (bool) $template->is_active,
                'is_default' => (bool) $template->is_default,
                'user_overrides' => $template->user_overrides,
                'updated_by' => $template->updated_by,
                'created_at' => $template->created_at ?? $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * 옛 범위(scope_type, scope_value)를 알림 type 으로 바꿉니다 (IdentityNotificationType 규칙의 동결 사본).
     *
     * @param  string  $scopeType  provider_default | purpose | policy
     * @param  string  $scopeValue  목적 또는 정책 키
     * @return string|null 알림 type (알 수 없는 범위면 null)
     */
    private function typeFor(string $scopeType, string $scopeValue): ?string
    {
        if ($scopeType === 'provider_default') {
            return 'identity.default';
        }

        if ($scopeValue === '') {
            return null;
        }

        if ($scopeType === 'purpose') {
            return 'identity.purpose.'.$scopeValue;
        }

        if ($scopeType === 'policy') {
            $type = 'identity.policy.'.$scopeValue;

            return strlen($type) <= self::MAX_TYPE_LENGTH ? $type : 'identity.policy.~'.substr(sha1($scopeValue), 0, 20);
        }

        return null;
    }

    /**
     * 제거된 본인인증 메시지 권한과 역할 연결을 지웁니다 (멱등).
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    private function removePermissions(UpgradeContext $context): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $ids = DB::table('permissions')->whereIn('identifier', self::REMOVED_PERMISSIONS)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        }
        DB::table('permissions')->whereIn('id', $ids)->delete();

        $context->logger->info('[core:7.1.0] 본인인증 메시지 권한 제거 (알림 설정 권한 core.settings.* 로 대체)', [
            'removed' => $ids->count(),
        ]);
    }

    /**
     * 옛 본인인증 메시지·알림 정의 캐시를 비웁니다 (태그를 지원하지 않는 드라이버는 건너뜀 —
     * 업데이트 마지막 단계의 전체 캐시 정리가 다시 비운다).
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    private function invalidateCaches(UpgradeContext $context): void
    {
        try {
            Cache::tags(['identity_message', 'notification'])->flush();
        } catch (\Throwable) {
            $context->logger->info('[core:7.1.0] 캐시 드라이버가 태그를 지원하지 않아 캐시 정리는 업데이트 마지막 단계에 맡깁니다');
        }
    }

    /**
     * 옛 다국어 값의 로케일이 현재 값과 모두 같은지 판정합니다.
     *
     * 옛 값에 있는 로케일만 비교한다 — 현재 값에는 언어팩이 넣은 로케일이 더 있을 수 있다.
     * 앞뒤 공백 차이는 같은 값으로 본다(03 의 거짓 표식 판정과 같은 기준).
     *
     * @param  mixed  $legacy  옛 다국어 JSON
     * @param  mixed  $current  현재 다국어 JSON
     * @return bool 옛 값의 모든 로케일이 같으면 true
     */
    private function sameLocales(mixed $legacy, mixed $current): bool
    {
        $old = is_string($legacy) ? json_decode($legacy, true) : $legacy;
        $now = is_string($current) ? json_decode($current, true) : $current;

        if (! is_array($old) || $old === []) {
            return true;
        }

        if (! is_array($now)) {
            return false;
        }

        foreach ($old as $locale => $value) {
            if (trim((string) $value) !== trim((string) ($now[$locale] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    /**
     * JSON 목록 컬럼을 배열로 읽습니다.
     *
     * @param  mixed  $value  컬럼 값 (JSON 문자열 또는 null)
     * @return array<int, string> 목록 (해석 불가면 빈 배열)
     */
    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
